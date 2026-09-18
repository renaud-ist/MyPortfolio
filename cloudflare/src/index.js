import bcrypt from 'bcryptjs';

const jsonHeaders = { 'content-type': 'application/json; charset=utf-8' };
const allowedOrigins = new Set([
  'https://renaud-ist.github.io',
  'http://localhost:8080',
  'http://127.0.0.1:8080',
]);

function corsHeaders(origin) {
  if (!origin || !allowedOrigins.has(origin)) {
    return {};
  }

  return {
    'access-control-allow-origin': origin,
    'access-control-allow-methods': 'GET, POST, PATCH, DELETE, OPTIONS',
    'access-control-allow-headers': 'Content-Type, Authorization',
    'access-control-max-age': '86400',
    vary: 'Origin',
  };
}

function jsonResponse(payload, status, headers) {
  return new Response(JSON.stringify(payload), {
    status,
    headers: { ...jsonHeaders, ...headers },
  });
}

function isValidEmail(email) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

async function rateLimitKey(request) {
  const clientIp = request.headers.get('CF-Connecting-IP') || 'unknown';
  const userAgent = request.headers.get('User-Agent') || 'unknown';
  const value = new TextEncoder().encode(`api|${clientIp}|${userAgent}`);
  const digest = await crypto.subtle.digest('SHA-256', value);

  return [...new Uint8Array(digest)]
    .map((byte) => byte.toString(16).padStart(2, '0'))
    .join('');
}

function bytesToHex(bytes) {
  return [...bytes]
    .map((byte) => byte.toString(16).padStart(2, '0'))
    .join('');
}

function generateAuthToken() {
  const bytes = new Uint8Array(32);
  crypto.getRandomValues(bytes);

  return bytesToHex(bytes);
}

async function hashAuthToken(token) {
  const value = new TextEncoder().encode(token);
  const digest = await crypto.subtle.digest('SHA-256', value);

  return bytesToHex(new Uint8Array(digest));
}

async function verifyPassword(plaintextPassword, storedHash) {
  return bcrypt.compare(plaintextPassword, storedHash);
}

function extractBearerToken(request) {
  const header = request.headers.get('Authorization')?.trim() || '';
  const match = header.match(/^Bearer\s+(\S+)$/i);

  return match ? match[1] : null;
}

async function findActiveAdminToken(env, tokenHash) {
  const result = await env.DB.prepare(
    `SELECT admin_tokens.id AS token_id, admin_tokens.admin_user_id,
            admin_tokens.expires_at, admin_tokens.revoked_at,
            admin_users.id, admin_users.email, admin_users.username,
            admin_users.role, admin_users.is_active
     FROM admin_tokens
     INNER JOIN admin_users ON admin_users.id = admin_tokens.admin_user_id
     WHERE admin_tokens.token_hash = ?
       AND admin_tokens.revoked_at IS NULL
       AND admin_users.is_active = 1
       AND admin_tokens.expires_at > CURRENT_TIMESTAMP
     LIMIT 1`
  ).bind(tokenHash).first();

  return result || null;
}

async function updateAdminTokenLastUsed(env, tokenId) {
  return env.DB.prepare(
    'UPDATE admin_tokens SET last_used_at = CURRENT_TIMESTAMP WHERE id = ?'
  ).bind(tokenId).run();
}

function adminIdentity(adminToken) {
  return {
    id: String(adminToken.id),
    email: adminToken.email,
    username: adminToken.username,
    role: adminToken.role,
  };
}

async function sendReplyEmail(env, conversation, body, fetchImplementation = fetch) {
  const apiKey = env.BREVO_API_KEY;
  const senderEmail = env.REPLY_FROM_EMAIL;
  if (!apiKey || !senderEmail) {
    return { enabled: false, sent: false, status: 'not_configured' };
  }

  try {
    const response = await fetchImplementation('https://api.brevo.com/v3/smtp/email', {
      method: 'POST',
      headers: {
        accept: 'application/json',
        'api-key': apiKey,
        'content-type': 'application/json',
      },
      body: JSON.stringify({
        sender: {
          email: senderEmail,
          name: env.REPLY_FROM_NAME || 'MyPortfolio',
        },
        to: [{ email: conversation.contact_email }],
        subject: conversation.subject || 'Reply to your portfolio message',
        textContent: body,
      }),
    });

    if (!response.ok) {
      return { enabled: true, sent: false, status: 'provider_error' };
    }

    return { enabled: true, sent: true, status: 'sent' };
  } catch (error) {
    return { enabled: true, sent: false, status: 'network_error' };
  }
}

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    const headers = corsHeaders(request.headers.get('Origin'));

    if (request.method === 'OPTIONS' && headers['access-control-allow-origin']) {
      return new Response(null, { status: 204, headers });
    }

    if (request.method === 'POST' && url.pathname === '/api/auth/logout') {
      const rawToken = extractBearerToken(request);
      if (!rawToken) {
        return jsonResponse({ ok: false, error: 'Authentication required.' }, 401, headers);
      }

      try {
        const tokenHash = await hashAuthToken(rawToken);
        const activeToken = await findActiveAdminToken(env, tokenHash);

        if (activeToken) {
          await env.DB.prepare(
            'UPDATE admin_tokens SET revoked_at = COALESCE(revoked_at, CURRENT_TIMESTAMP) WHERE id = ?'
          ).bind(activeToken.token_id).run();

          return jsonResponse({ ok: true }, 200, headers);
        }

        const revokedToken = await env.DB.prepare(
          `SELECT admin_tokens.id
           FROM admin_tokens
           INNER JOIN admin_users ON admin_users.id = admin_tokens.admin_user_id
           WHERE admin_tokens.token_hash = ?
             AND admin_tokens.revoked_at IS NOT NULL
             AND admin_tokens.expires_at > CURRENT_TIMESTAMP
             AND admin_users.is_active = 1
           LIMIT 1`
        ).bind(tokenHash).first();

        if (revokedToken) {
          return jsonResponse({ ok: true }, 200, headers);
        }

        return jsonResponse({ ok: false, error: 'Authentication required.' }, 401, headers);
      } catch (error) {
        return jsonResponse({ ok: false, error: 'Authentication service is temporarily unavailable.' }, 503, headers);
      }
    }

    if (request.method === 'GET' && url.pathname === '/api/auth/me') {
      const rawToken = extractBearerToken(request);
      if (!rawToken) {
        return jsonResponse({ ok: false, error: 'Authentication required.' }, 401, headers);
      }

      try {
        const tokenHash = await hashAuthToken(rawToken);
        const activeToken = await findActiveAdminToken(env, tokenHash);
        if (!activeToken) {
          return jsonResponse({ ok: false, error: 'Authentication required.' }, 401, headers);
        }

        await updateAdminTokenLastUsed(env, activeToken.token_id);

        return jsonResponse({
          ok: true,
          admin: adminIdentity(activeToken),
        }, 200, headers);
      } catch (error) {
        return jsonResponse({ ok: false, error: 'Authentication service is temporarily unavailable.' }, 503, headers);
      }
    }

    if (request.method === 'POST' && url.pathname === '/api/auth/login') {
      const contentType = request.headers.get('Content-Type')?.split(';', 1)[0].trim().toLowerCase();
      if (contentType !== 'application/json') {
        return jsonResponse({ ok: false, error: 'Content-Type must be application/json.' }, 415, headers);
      }

      try {
        let body;
        try {
          body = await request.json();
        } catch (error) {
          return jsonResponse({ ok: false, error: 'Request body must contain valid JSON.' }, 400, headers);
        }

        const fields = body && typeof body === 'object' && !Array.isArray(body)
          ? Object.keys(body)
          : [];
        if (!body || typeof body !== 'object' || Array.isArray(body)
          || fields.length !== 2 || !fields.includes('email') || !fields.includes('password')) {
          return jsonResponse({ ok: false, error: 'Request body must contain only email and password.' }, 400, headers);
        }

        const email = typeof body.email === 'string' ? body.email.trim() : '';
        const password = typeof body.password === 'string' ? body.password : '';
        if (!email || email.length > 180 || !isValidEmail(email) || !password) {
          return jsonResponse({ ok: false, error: 'Invalid credentials.' }, 401, headers);
        }

        const clientIp = request.headers.get('CF-Connecting-IP') || 'unknown';
        const rateKey = await hashAuthToken(`auth|${clientIp}`);
        const now = Math.floor(Date.now() / 1000);
        const reservationMarker = `${new Date().toISOString()}-${crypto.randomUUID()}`;
        const reservation = await env.DB.batch([
          env.DB.prepare(
            `INSERT INTO contact_rate_limits (rate_key, last_submission, updated_at)
             VALUES (?, ?, ?)
             ON CONFLICT(rate_key) DO UPDATE SET
               last_submission = excluded.last_submission,
               updated_at = excluded.updated_at
             WHERE contact_rate_limits.last_submission <= excluded.last_submission - 10`
          ).bind(rateKey, now, reservationMarker),
          env.DB.prepare(
            `SELECT id, email, username, role, password_hash
             FROM admin_users
             WHERE email = ?
               AND is_active = 1
               AND EXISTS (
                 SELECT 1 FROM contact_rate_limits
                 WHERE rate_key = ? AND updated_at = ?
               )
             LIMIT 1`
          ).bind(email, rateKey, reservationMarker),
        ]);

        if ((reservation[0]?.meta?.changes ?? 0) !== 1) {
          return jsonResponse({
            ok: false,
            message: 'Too many login attempts. Please try again later.',
          }, 429, headers);
        }

        const admin = reservation[1]?.results?.[0] || null;
        if (!admin || !(await verifyPassword(password, admin.password_hash))) {
          return jsonResponse({ ok: false, error: 'Invalid credentials.' }, 401, headers);
        }

        const rawToken = generateAuthToken();
        const tokenHash = await hashAuthToken(rawToken);
        const expiresAt = new Date(Date.now() + 1800 * 1000);
        const expiresAtIso = expiresAt.toISOString();
        const expiresAtSql = expiresAtIso.slice(0, 19).replace('T', ' ');

        await env.DB.batch([
          env.DB.prepare(
            `INSERT INTO admin_tokens
              (admin_user_id, token_hash, expires_at, ip_address, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)`
          ).bind(
            admin.id,
            tokenHash,
            expiresAtSql,
            clientIp,
            request.headers.get('User-Agent') || null
          ),
          env.DB.prepare(
            'UPDATE admin_users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?'
          ).bind(admin.id),
          env.DB.prepare(
            `UPDATE contact_rate_limits
             SET updated_at = CURRENT_TIMESTAMP
             WHERE rate_key = ? AND updated_at = ?`
          ).bind(rateKey, reservationMarker),
        ]);

        return jsonResponse({
          ok: true,
          token: rawToken,
          expires_at: expiresAtIso,
          admin: adminIdentity(admin),
        }, 200, headers);
      } catch (error) {
        return jsonResponse({ ok: false, error: 'Authentication service is temporarily unavailable.' }, 500, headers);
      }
    }

    if (request.method === 'GET' && url.pathname === '/api/conversations') {
      const rawToken = extractBearerToken(request);
      if (!rawToken) {
        return jsonResponse({ ok: false, error: 'Authentication required.' }, 401, headers);
      }

      try {
        const tokenHash = await hashAuthToken(rawToken);
        const activeToken = await findActiveAdminToken(env, tokenHash);
        if (!activeToken) {
          return jsonResponse({ ok: false, error: 'Authentication required.' }, 401, headers);
        }

        await updateAdminTokenLastUsed(env, activeToken.token_id);

        const parsePositiveInteger = (name, defaultValue, maximum) => {
          const rawValue = url.searchParams.get(name);
          if (rawValue === null) {
            return defaultValue;
          }

          if (!/^[+]?\d+$/.test(rawValue)) {
            throw new Error(`Invalid ${name}.`);
          }

          const value = Number(rawValue);
          if (!Number.isSafeInteger(value) || value < 1 || value > maximum) {
            throw new Error(`Invalid ${name}.`);
          }

          return value;
        };

        const parseBooleanInteger = (name) => {
          const rawValue = url.searchParams.get(name);
          if (rawValue === null) {
            return null;
          }

          if (!/^[+]?\d+$/.test(rawValue)) {
            throw new Error(`Invalid ${name}.`);
          }

          const value = Number(rawValue);
          if (!Number.isSafeInteger(value) || (value !== 0 && value !== 1)) {
            throw new Error(`Invalid ${name}.`);
          }

          return value;
        };

        let page;
        let perPage;
        let isRead;
        let isArchived;
        try {
          page = parsePositiveInteger('page', 1, Number.MAX_SAFE_INTEGER);
          perPage = parsePositiveInteger('per_page', 20, 100);
          isRead = parseBooleanInteger('is_read');
          isArchived = parseBooleanInteger('is_archived');
        } catch (error) {
          return jsonResponse({ ok: false, error: error.message }, 400, headers);
        }

        const conditions = [];
        const parameters = [];
        const status = url.searchParams.get('status');
        if (status !== null) {
          const trimmedStatus = status.trim();
          if (!trimmedStatus) {
            return jsonResponse({ ok: false, error: 'Invalid status.' }, 400, headers);
          }

          const statusRows = await env.DB.prepare(
            'SELECT DISTINCT status FROM conversations WHERE status IS NOT NULL'
          ).all();
          if (!statusRows.results.some((row) => row.status === trimmedStatus)) {
            return jsonResponse({ ok: false, error: 'Invalid status.' }, 400, headers);
          }

          conditions.push('conversations.status = ?');
          parameters.push(trimmedStatus);
        }
        if (isRead !== null) {
          conditions.push(isRead === 1 ? 'conversations.read_at IS NOT NULL' : 'conversations.read_at IS NULL');
        }
        if (isArchived !== null) {
          conditions.push(isArchived === 1 ? 'conversations.archived_at IS NOT NULL' : 'conversations.archived_at IS NULL');
        }

        const whereSql = conditions.length ? ` WHERE ${conditions.join(' AND ')}` : '';
        const count = await env.DB.prepare(
          `SELECT COUNT(*) AS total FROM conversations${whereSql}`
        ).bind(...parameters).first();
        const total = Number(count?.total || 0);
        const totalPages = total === 0 ? 0 : Math.ceil(total / perPage);

        if (page > Math.max(1, totalPages)) {
          return jsonResponse({ ok: false, error: 'Page is out of range.' }, 400, headers);
        }

        const offset = (page - 1) * perPage;
        const list = await env.DB.prepare(
          `SELECT conversations.id, conversations.contact_name, conversations.contact_email, conversations.subject,
                  conversations.status, conversations.read_at, conversations.archived_at, conversations.created_at,
                  conversations.updated_at, conversations.last_message_at,
                  latest.body AS latest_message_preview, latest.sender_type AS latest_sender_type
           FROM conversations
           LEFT JOIN conversation_messages AS latest
              ON latest.id = (
                SELECT newest.id
                FROM conversation_messages AS newest
                WHERE newest.conversation_id = conversations.id
                ORDER BY newest.created_at DESC, newest.id DESC
                LIMIT 1
              )${whereSql}
           ORDER BY conversations.updated_at DESC, conversations.id DESC
           LIMIT ? OFFSET ?`
        ).bind(...parameters, perPage, offset).all();

        return jsonResponse({
          ok: true,
          data: list.results.map((row) => ({
            id: String(row.id),
            contact_name: row.contact_name,
            contact_email: row.contact_email,
            subject: row.subject,
            status: row.status,
            is_read: row.read_at !== null,
            is_archived: row.archived_at !== null,
            created_at: row.created_at,
            updated_at: row.updated_at,
            last_message_at: row.last_message_at,
            latest_message_preview: row.latest_message_preview,
            latest_sender_type: row.latest_sender_type,
          })),
          pagination: {
            page,
            per_page: perPage,
            total,
            total_pages: totalPages,
          },
        }, 200, headers);
      } catch (error) {
        return jsonResponse({ ok: false, error: 'Conversation service is temporarily unavailable.' }, 503, headers);
      }
    }

    if (request.method === 'GET' && url.pathname === '/api/notifications') {
      const rawToken = extractBearerToken(request);
      if (!rawToken) {
        return jsonResponse({ ok: false, error: 'Authentication required.' }, 401, headers);
      }

      try {
        const tokenHash = await hashAuthToken(rawToken);
        const activeToken = await findActiveAdminToken(env, tokenHash);
        if (!activeToken) {
          return jsonResponse({ ok: false, error: 'Authentication required.' }, 401, headers);
        }

        await updateAdminTokenLastUsed(env, activeToken.token_id);

        const parsePositiveInteger = (name, defaultValue, maximum) => {
          const rawValue = url.searchParams.get(name);
          if (rawValue === null) {
            return defaultValue;
          }

          if (!/^[+]?\d+$/.test(rawValue)) {
            throw new Error(`Invalid ${name}.`);
          }

          const value = Number(rawValue);
          if (!Number.isSafeInteger(value) || value < 1 || value > maximum) {
            throw new Error(`Invalid ${name}.`);
          }

          return value;
        };

        let page;
        let perPage;

        try {
          page = parsePositiveInteger('page', 1, Number.MAX_SAFE_INTEGER);
          perPage = parsePositiveInteger('per_page', 20, 100);
        } catch (error) {
          return jsonResponse({ ok: false, error: error.message }, 400, headers);
        }

        const conditions = ['notifications.recipient_type = ?'];
        const parameters = ['admin'];

        const status = url.searchParams.get('status');
        if (status !== null) {
          const trimmedStatus = status.trim();

          if (!trimmedStatus) {
            return jsonResponse({ ok: false, error: 'Invalid status.' }, 400, headers);
          }

          const statusRows = await env.DB.prepare(
            'SELECT DISTINCT status FROM notifications WHERE status IS NOT NULL'
          ).all();

          if (!statusRows.results.some((row) => row.status === trimmedStatus)) {
            return jsonResponse({ ok: false, error: 'Invalid status.' }, 400, headers);
          }

          conditions.push('notifications.status = ?');
          parameters.push(trimmedStatus);
        }

        const notificationType = url.searchParams.get('notification_type');
        if (notificationType !== null) {
          const trimmedType = notificationType.trim();

          if (!trimmedType) {
            return jsonResponse({ ok: false, error: 'Invalid notification_type.' }, 400, headers);
          }

          const typeRows = await env.DB.prepare(
            'SELECT DISTINCT notification_type FROM notifications WHERE notification_type IS NOT NULL'
          ).all();

          if (!typeRows.results.some((row) => row.notification_type === trimmedType)) {
            return jsonResponse({ ok: false, error: 'Invalid notification_type.' }, 400, headers);
          }

          conditions.push('notifications.notification_type = ?');
          parameters.push(trimmedType);
        }

        const whereSql = ` WHERE ${conditions.join(' AND ')}`;

        const count = await env.DB.prepare(
          `SELECT COUNT(*) AS total
           FROM notifications
           ${whereSql}`
        ).bind(...parameters).first();

        const total = Number(count?.total || 0);
        const totalPages = total === 0 ? 0 : Math.ceil(total / perPage);

        if (page > Math.max(1, totalPages)) {
          return jsonResponse({ ok: false, error: 'Page is out of range.' }, 400, headers);
        }

        const offset = (page - 1) * perPage;

        const list = await env.DB.prepare(
          `SELECT notifications.id,
                  notifications.conversation_id,
                  notifications.conversation_message_id,
                  notifications.recipient_type,
                  notifications.notification_type,
                  notifications.status,
                  notifications.attempt_count,
                  notifications.sent_at,
                  notifications.created_at,
                  notifications.updated_at,
                  conversations.contact_name,
                  conversations.contact_email,
                  conversations.subject,
                  conversation_messages.sender_type
           FROM notifications
           LEFT JOIN conversations
             ON conversations.id = notifications.conversation_id
           LEFT JOIN conversation_messages
             ON conversation_messages.id = notifications.conversation_message_id
           ${whereSql}
           ORDER BY notifications.created_at DESC, notifications.id DESC
           LIMIT ? OFFSET ?`
        ).bind(...parameters, perPage, offset).all();

        return jsonResponse({
          ok: true,
          data: list.results.map((row) => ({
            id: String(row.id),
            conversation_id: row.conversation_id === null ? null : String(row.conversation_id),
            conversation_message_id: row.conversation_message_id === null ? null : String(row.conversation_message_id),
            recipient_type: row.recipient_type,
            notification_type: row.notification_type,
            status: row.status,
            attempt_count: Number(row.attempt_count),
            sent_at: row.sent_at,
            created_at: row.created_at,
            updated_at: row.updated_at,
            contact_name: row.contact_name,
            contact_email: row.contact_email,
            subject: row.subject,
            message_sender_type: row.sender_type,
          })),
          pagination: {
            page,
            per_page: perPage,
            total,
            total_pages: totalPages,
          },
          read_state_supported: false,
        }, 200, headers);
      } catch (error) {
        return jsonResponse({
          ok: false,
          error: 'Notification service is temporarily unavailable.'
        }, 503, headers);
      }
    }

    if (request.method === 'DELETE' && url.pathname === '/api/notifications') {
      const rawToken = extractBearerToken(request);
      if (!rawToken) {
        return jsonResponse({ ok: false, error: 'Authentication required.' }, 401, headers);
      }

      try {
        const tokenHash = await hashAuthToken(rawToken);
        const activeToken = await findActiveAdminToken(env, tokenHash);
        if (!activeToken) {
          return jsonResponse({ ok: false, error: 'Authentication required.' }, 401, headers);
        }

        await updateAdminTokenLastUsed(env, activeToken.token_id);

        const rawId = url.searchParams.get('id');

        if (rawId === null || !/^\d+$/.test(rawId)) {
          return jsonResponse({ ok: false, error: 'Invalid notification id.' }, 400, headers);
        }

        const notificationId = Number(rawId);

        if (!Number.isSafeInteger(notificationId) || notificationId < 1) {
          return jsonResponse({ ok: false, error: 'Invalid notification id.' }, 400, headers);
        }

        const notification = await env.DB.prepare(
          `SELECT id
           FROM notifications
           WHERE id = ?
             AND recipient_type = 'admin'`
        ).bind(notificationId).first();

        if (!notification) {
          return jsonResponse({ ok: false, error: 'Notification not found.' }, 404, headers);
        }

        const result = await env.DB.prepare(
          `DELETE FROM notifications
           WHERE id = ?
             AND recipient_type = 'admin'`
        ).bind(notificationId).run();

        if (Number(result?.meta?.changes || 0) !== 1) {
          return jsonResponse({ ok: false, error: 'Notification could not be deleted.' }, 409, headers);
        }

        return jsonResponse({
          ok: true,
          data: {
            id: String(notificationId),
            deleted: true,
          },
        }, 200, headers);
      } catch (error) {
        return jsonResponse({
          ok: false,
          error: 'Notification service is temporarily unavailable.'
        }, 503, headers);
      }
    }
    if (request.method === 'POST' && url.pathname === '/api/conversation/reply') {
      const contentType = request.headers.get('Content-Type')?.split(';', 1)[0].trim().toLowerCase();

      if (contentType !== 'application/json') {
        return jsonResponse({
          ok: false,
          error: 'Content-Type must be application/json.'
        }, 415, headers);
      }

      const rawToken = extractBearerToken(request);
      if (!rawToken) {
        return jsonResponse({ ok: false, error: 'Authentication required.' }, 401, headers);
      }

      let activeToken;

      try {
        const tokenHash = await hashAuthToken(rawToken);
        activeToken = await findActiveAdminToken(env, tokenHash);
      } catch (error) {
        return jsonResponse({ ok: false, error: 'Authentication failed.' }, 401, headers);
      }

      if (!activeToken) {
        return jsonResponse({ ok: false, error: 'Authentication required.' }, 401, headers);
      }

      try {
        await updateAdminTokenLastUsed(env, activeToken.token_id);
      } catch (error) {
        return jsonResponse({
          ok: false,
          error: 'Authentication service is temporarily unavailable.'
        }, 503, headers);
      }

      const rawId = url.searchParams.get('id');

      if (rawId === null || !/^[+]?[1-9]\d*$/.test(rawId)) {
        return jsonResponse({
          ok: false,
          error: 'A valid conversation id is required.'
        }, 400, headers);
      }

      const conversationId = Number(rawId);

      if (!Number.isSafeInteger(conversationId) || conversationId < 1) {
        return jsonResponse({
          ok: false,
          error: 'A valid conversation id is required.'
        }, 400, headers);
      }

      let payload;

      try {
        payload = await request.json();
      } catch (error) {
        return jsonResponse({
          ok: false,
          error: 'Request body must contain valid JSON.'
        }, 400, headers);
      }

      if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
        return jsonResponse({
          ok: false,
          error: 'Request body must contain a JSON object.'
        }, 400, headers);
      }

      const fields = Object.keys(payload);

      if (fields.some((field) => field !== 'body')) {
        return jsonResponse({
          ok: false,
          error: 'Unsupported reply field.'
        }, 400, headers);
      }

      if (typeof payload.body !== 'string') {
        return jsonResponse({
          ok: false,
          error: 'Reply body must be a string.'
        }, 422, headers);
      }

      const body = payload.body.trim();

      if (!body) {
        return jsonResponse({
          ok: false,
          error: 'Reply body is required.'
        }, 422, headers);
      }

      if (body.length > 65535) {
        return jsonResponse({
          ok: false,
          error: 'Reply body is too long.'
        }, 422, headers);
      }

      try {
        const conversation = await env.DB.prepare(
          `SELECT id, contact_name, contact_email, subject, status,
                  read_at, archived_at, created_at, updated_at, last_message_at
           FROM conversations
           WHERE id = ?
           LIMIT 1`
        ).bind(conversationId).first();

        if (!conversation) {
          return jsonResponse({
            ok: false,
            error: 'Conversation not found.'
          }, 404, headers);
        }

        const admin = adminIdentity(activeToken);

        const auditMetadata = JSON.stringify({
          conversation_id: conversationId,
        });

        const result = await env.DB.batch([
          env.DB.prepare(
            `INSERT INTO conversation_messages
              (conversation_id, sender_type, sender_admin_id,
               sender_name, sender_email, body, created_at)
             VALUES (?, 'admin', ?, ?, ?, ?, CURRENT_TIMESTAMP)`
          ).bind(
            conversationId,
            admin.id,
            admin.username,
            admin.email,
            body
          ),

          env.DB.prepare(
            `UPDATE conversations
             SET last_message_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?`
          ).bind(conversationId),

          env.DB.prepare(
            `INSERT INTO notifications
              (conversation_id, conversation_message_id, recipient_type,
               notification_type, status, created_at, updated_at)
             VALUES (?, last_insert_rowid(), 'admin', 'new_admin_reply',
                     'pending', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)`
          ).bind(conversationId),

          env.DB.prepare(
            `INSERT INTO audit_logs
              (admin_user_id, action, entity_type, entity_id,
               metadata_json, ip_address, user_agent, created_at)
             VALUES (?, ?, ?,
                     (SELECT conversation_message_id
                      FROM notifications
                      WHERE id = last_insert_rowid()),
                     ?, ?, ?, CURRENT_TIMESTAMP)`
          ).bind(
            admin.id,
            'conversation.reply',
            'conversation_message',
            auditMetadata,
            request.headers.get('CF-Connecting-IP') || null,
            (request.headers.get('User-Agent') || '').slice(0, 512) || null
          ),
        ]);

        const insertedMessageId = Number(result[0]?.meta?.last_row_id || 0);

        if (!insertedMessageId) {
          return jsonResponse({
            ok: false,
            error: 'The reply could not be saved. Please try again later.'
          }, 503, headers);
        }

        const updated = await env.DB.prepare(
          `SELECT last_message_at
           FROM conversations
           WHERE id = ?`
        ).bind(conversationId).first();

        const email = await sendReplyEmail(env, conversation, body);

        return jsonResponse({
          ok: true,
          data: {
            message: {
              id: String(insertedMessageId),
              conversation_id: String(conversationId),
              sender_type: 'admin',
              sender_admin_id: String(admin.id),
              sender_name: admin.username,
              sender_email: admin.email,
              body,
            },
            conversation: {
              id: String(conversationId),
              last_message_at: updated?.last_message_at || null,
            },
            email,
          },
        }, 201, headers);
      } catch (error) {
        return jsonResponse({
          ok: false,
          error: 'The reply could not be saved. Please try again later.'
        }, 503, headers);
      }
    }
    if (url.pathname === '/api/conversation') {
      if (request.method !== 'GET' && request.method !== 'PATCH' && request.method !== 'DELETE') {
        return new Response(null, {
          status: 405,
          headers: { ...headers, allow: 'GET, PATCH, DELETE, OPTIONS' },
        });
      }

      const rawToken = extractBearerToken(request);
      if (!rawToken) {
        return jsonResponse({ ok: false, error: 'Authentication required.' }, 401, headers);
      }

      let activeToken;
      try {
        const tokenHash = await hashAuthToken(rawToken);
        activeToken = await findActiveAdminToken(env, tokenHash);
      } catch (error) {
        return jsonResponse({ ok: false, error: 'Authentication failed.' }, 401, headers);
      }

      if (!activeToken) {
        return jsonResponse({ ok: false, error: 'Authentication required.' }, 401, headers);
      }

      try {
        await updateAdminTokenLastUsed(env, activeToken.token_id);
      } catch (error) {
        return jsonResponse({ ok: false, error: 'Authentication service is temporarily unavailable.' }, 503, headers);
      }

      const rawId = url.searchParams.get('id');
      if (rawId === null || !/^[+]?[1-9]\d*$/.test(rawId)) {
        return jsonResponse({ ok: false, error: 'A valid conversation id is required.' }, 400, headers);
      }

      const conversationId = Number(rawId);
      if (!Number.isSafeInteger(conversationId) || conversationId < 1) {
        return jsonResponse({ ok: false, error: 'A valid conversation id is required.' }, 400, headers);
      }

      const conversationSql = `SELECT id, contact_name, contact_email, subject, status, read_at, archived_at,
                                      created_at, updated_at, last_message_at
                               FROM conversations
                               WHERE id = ?
                               LIMIT 1`;
      const messageSql = `SELECT id, sender_type, sender_admin_id, sender_name, sender_email,
                                 body, read_at, created_at
                          FROM conversation_messages
                          WHERE conversation_id = ?
                          ORDER BY created_at ASC, id ASC`;

      const conversationData = async (id) => {
        const conversation = await env.DB.prepare(conversationSql).bind(id).first();
        if (!conversation) {
          return null;
        }

        const messages = await env.DB.prepare(messageSql).bind(id).all();
        return {
          id: String(conversation.id),
          contact_name: conversation.contact_name,
          contact_email: conversation.contact_email,
          subject: conversation.subject,
          status: conversation.status,
          is_read: conversation.read_at !== null,
          read_at: conversation.read_at,
          is_archived: conversation.archived_at !== null,
          archived_at: conversation.archived_at,
          created_at: conversation.created_at,
          updated_at: conversation.updated_at,
          last_message_at: conversation.last_message_at,
          messages: messages.results.map((message) => ({
            id: String(message.id),
            sender_type: message.sender_type,
            sender_admin_id: message.sender_admin_id === null ? null : String(message.sender_admin_id),
            sender_name: message.sender_name,
            sender_email: message.sender_email,
            body: message.body,
            is_read: message.read_at !== null,
            read_at: message.read_at,
            created_at: message.created_at,
          })),
        };
      };

      if (request.method === 'GET') {
        try {
          const data = await conversationData(conversationId);
          if (!data) {
            return jsonResponse({ ok: false, error: 'Conversation not found.' }, 404, headers);
          }

          return jsonResponse({ ok: true, data }, 200, headers);
        } catch (error) {
          return jsonResponse({ ok: false, error: 'Conversation service is temporarily unavailable.' }, 503, headers);
        }
      }

      if (request.method === 'DELETE') {
        try {
          const existing = await env.DB.prepare(
            'SELECT id FROM conversations WHERE id = ? LIMIT 1'
          ).bind(conversationId).first();
          if (!existing) {
            return jsonResponse({ ok: false, error: 'Conversation not found.' }, 404, headers);
          }

          await env.DB.batch([
            env.DB.prepare(
              `INSERT INTO audit_logs
                (admin_user_id, action, entity_type, entity_id, metadata_json, ip_address, user_agent, created_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)`
            ).bind(
              activeToken.admin_user_id,
              'conversation.delete',
              'conversation',
              conversationId,
              JSON.stringify({ deleted: true }),
              request.headers.get('CF-Connecting-IP') || null,
              (request.headers.get('User-Agent') || '').slice(0, 512) || null
            ),
            env.DB.prepare(
              'DELETE FROM conversations WHERE id = ?'
            ).bind(conversationId),
          ]);

          return jsonResponse({
            ok: true,
            data: { id: String(conversationId), deleted: true },
          }, 200, headers);
        } catch (error) {
          return jsonResponse({ ok: false, error: 'Conversation service is temporarily unavailable.' }, 503, headers);
        }
      }

      const contentType = request.headers.get('Content-Type')?.split(';', 1)[0].trim().toLowerCase();
      if (contentType !== 'application/json') {
        return jsonResponse({ ok: false, error: 'Content-Type must be application/json.' }, 415, headers);
      }

      let payload;
      try {
        payload = await request.json();
      } catch (error) {
        return jsonResponse({ ok: false, error: 'Request body must contain valid JSON.' }, 400, headers);
      }

      if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
        return jsonResponse({ ok: false, error: 'Request body must contain a JSON object.' }, 400, headers);
      }

      const allowedFields = ['is_read', 'is_archived', 'status'];
      if (Object.keys(payload).some((field) => !allowedFields.includes(field))) {
        return jsonResponse({ ok: false, error: 'Unsupported conversation field.' }, 400, headers);
      }
      if (Object.keys(payload).length === 0) {
        return jsonResponse({ ok: false, error: 'At least one conversation field is required.' }, 400, headers);
      }

      const changes = {};
      if (Object.prototype.hasOwnProperty.call(payload, 'is_read')) {
        if (typeof payload.is_read !== 'boolean') {
          return jsonResponse({ ok: false, error: 'is_read must be boolean.' }, 422, headers);
        }
        changes.is_read = payload.is_read;
      }
      if (Object.prototype.hasOwnProperty.call(payload, 'is_archived')) {
        if (typeof payload.is_archived !== 'boolean') {
          return jsonResponse({ ok: false, error: 'is_archived must be boolean.' }, 422, headers);
        }
        changes.is_archived = payload.is_archived;
      }
      if (Object.prototype.hasOwnProperty.call(payload, 'status')) {
        let statusValues;
        try {
          statusValues = await env.DB.prepare(
            'SELECT DISTINCT status FROM conversations WHERE status IS NOT NULL'
          ).all();
        } catch (error) {
          return jsonResponse({ ok: false, error: 'Conversation service is temporarily unavailable.' }, 503, headers);
        }
        if (typeof payload.status !== 'string' || !statusValues.results.some((row) => row.status === payload.status)) {
          return jsonResponse({ ok: false, error: 'Invalid status.' }, 422, headers);
        }
        changes.status = payload.status;
      }

      try {
        const existing = await env.DB.prepare(
          'SELECT id FROM conversations WHERE id = ? LIMIT 1'
        ).bind(conversationId).first();
        if (!existing) {
          return jsonResponse({ ok: false, error: 'Conversation not found.' }, 404, headers);
        }

        const assignments = ['updated_at = CURRENT_TIMESTAMP'];
        const updateParameters = [];
        if (Object.prototype.hasOwnProperty.call(changes, 'is_read')) {
          assignments.push(changes.is_read ? 'read_at = CURRENT_TIMESTAMP' : 'read_at = NULL');
        }
        if (Object.prototype.hasOwnProperty.call(changes, 'is_archived')) {
          assignments.push(changes.is_archived ? 'archived_at = CURRENT_TIMESTAMP' : 'archived_at = NULL');
        }
        if (Object.prototype.hasOwnProperty.call(changes, 'status')) {
          assignments.push('status = ?');
          updateParameters.push(changes.status);
        }
        updateParameters.push(conversationId);

        await env.DB.batch([
          env.DB.prepare(
            `UPDATE conversations
             SET ${assignments.join(', ')}
             WHERE id = ?`
          ).bind(...updateParameters),
          env.DB.prepare(
            `INSERT INTO audit_logs
              (admin_user_id, action, entity_type, entity_id, metadata_json, ip_address, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)`
          ).bind(
            activeToken.admin_user_id,
            'conversation.update',
            'conversation',
            conversationId,
            JSON.stringify({ changes }),
            request.headers.get('CF-Connecting-IP') || null,
            (request.headers.get('User-Agent') || '').slice(0, 512) || null
          ),
        ]);

        const data = await conversationData(conversationId);
        return jsonResponse({ ok: true, data }, 200, headers);
      } catch (error) {
        return jsonResponse({ ok: false, error: 'Conversation service is temporarily unavailable.' }, 503, headers);
      }
    }

    if (request.method === 'POST' && url.pathname === '/api/contact') {
      const contentType = request.headers.get('Content-Type')?.split(';', 1)[0].trim().toLowerCase();
      if (contentType !== 'application/json') {
        return jsonResponse({ ok: false, error: 'Content-Type must be application/json.' }, 415, headers);
      }

      try {
        const body = await request.json();
        if (!body || typeof body !== 'object' || Array.isArray(body)) {
          return jsonResponse({ ok: false, error: 'Request body must be a JSON object.' }, 400, headers);
        }

        const name = typeof body.name === 'string' ? body.name.trim() : '';
        const email = typeof body.email === 'string' ? body.email.trim() : '';
        const subject = typeof body.subject === 'string' ? body.subject.trim() : '';
        const message = typeof body.message === 'string' ? body.message.trim() : '';

        if (!name || name.length > 100
          || !email || email.length > 254 || !isValidEmail(email)
          || !subject || subject.length > 200
          || !message || message.length > 5000) {
          return jsonResponse({ ok: false, error: 'Invalid contact fields.' }, 400, headers);
        }

        const rateKey = await rateLimitKey(request);
        const now = Math.floor(Date.now() / 1000);
        const reservationMarker = `${new Date().toISOString()}-${crypto.randomUUID()}`;
        const batchResults = await env.DB.batch([
          env.DB.prepare(
            `INSERT INTO contact_rate_limits (rate_key, last_submission, updated_at)
             VALUES (?, ?, ?)
             ON CONFLICT(rate_key) DO UPDATE SET
               last_submission = excluded.last_submission,
               updated_at = excluded.updated_at
             WHERE contact_rate_limits.last_submission <= excluded.last_submission - 60`
          ).bind(rateKey, now, reservationMarker),
          env.DB.prepare(
            `INSERT INTO conversations
              (contact_name, contact_email, subject, status, created_at, updated_at, last_message_at)
             SELECT ?, ?, ?, 'open', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
             WHERE EXISTS (
               SELECT 1 FROM contact_rate_limits
               WHERE rate_key = ? AND updated_at = ?
             )`
          ).bind(name, email, subject, rateKey, reservationMarker),
          env.DB.prepare(
            `INSERT INTO conversation_messages
              (conversation_id, sender_type, sender_name, sender_email, body, created_at)
             SELECT last_insert_rowid(), 'visitor', ?, ?, ?, CURRENT_TIMESTAMP
             WHERE EXISTS (
               SELECT 1 FROM contact_rate_limits
               WHERE rate_key = ? AND updated_at = ?
             )`
          ).bind(name, email, message, rateKey, reservationMarker),
          env.DB.prepare(
            `INSERT INTO notifications
              (conversation_id, conversation_message_id, recipient_type, notification_type, status, created_at, updated_at)
             SELECT
               (SELECT conversation_id FROM conversation_messages WHERE id = last_insert_rowid()),
               last_insert_rowid(),
               'admin', 'new_contact_message', 'pending', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
             WHERE EXISTS (
               SELECT 1 FROM contact_rate_limits
               WHERE rate_key = ? AND updated_at = ?
             )`
          ).bind(rateKey, reservationMarker),
          env.DB.prepare(
            `UPDATE contact_rate_limits
             SET updated_at = CURRENT_TIMESTAMP
             WHERE rate_key = ? AND updated_at = ?`
          ).bind(rateKey, reservationMarker),
        ]);

        if ((batchResults[0]?.meta?.changes ?? 0) !== 1) {
          return jsonResponse({
            ok: false,
            message: 'Please wait a moment before sending another message.',
          }, 429, headers);
        }

        return jsonResponse({ ok: true, message: 'Your message has been received.' }, 201, headers);
      } catch (error) {
        return jsonResponse({ ok: false, error: 'The message could not be saved.' }, 500, headers);
      }
    }

    if (request.method !== 'GET' || url.pathname !== '/') {
      return new Response('Not Found', { status: 404, headers });
    }

    const result = await env.DB.prepare('SELECT 1 AS ok').run();

    return new Response(JSON.stringify({
      ok: true,
      service: 'myportfolio-api-proxy',
      database: 'connected',
      result: result.results,
    }), {
      headers: { ...jsonHeaders, ...headers },
    });
  },
};

export { sendReplyEmail };
