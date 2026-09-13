const jsonHeaders = { 'content-type': 'application/json; charset=utf-8' };
const allowedOrigin = 'https://renaud-ist.github.io';

function corsHeaders(origin) {
  if (origin !== allowedOrigin) {
    return {};
  }

  return {
    'access-control-allow-origin': allowedOrigin,
    'access-control-allow-methods': 'GET, POST, OPTIONS',
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

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    const headers = corsHeaders(request.headers.get('Origin'));

    if (request.method === 'OPTIONS' && headers['access-control-allow-origin']) {
      return new Response(null, { status: 204, headers });
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