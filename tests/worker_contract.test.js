import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import worker, { sendReplyEmail } from '../cloudflare/src/index.js';

const origin = 'https://renaud-ist.github.io';
const source = readFileSync(new URL('../cloudflare/src/index.js', import.meta.url), 'utf8');

function fakeDatabase() {
  const batches = [];
  return {
    batches,
    prepare(sql) {
      const statement = {
        sql,
        values: [],
        bind(...values) {
          statement.values = values;
          return statement;
        },
        async first() {
          if (sql.includes('FROM admin_tokens')) {
            return {
              token_id: 9,
              admin_user_id: 4,
              id: 4,
              email: 'admin@example.test',
              username: 'admin',
              role: 'admin',
              is_active: 1,
            };
          }
          if (sql.includes('FROM conversations')) {
            return { id: 12 };
          }
          return null;
        },
        async all() {
          return { results: [] };
        },
        async run() {
          return { success: true, meta: { changes: 1 } };
        },
      };
      return statement;
    },
    async batch(statements) {
      batches.push(statements);
      return statements.map(() => ({ success: true, meta: { changes: 1 } }));
    },
  };
}

test('allowed DELETE preflight returns 204 with DELETE CORS permission', async () => {
  const response = await worker.fetch(new Request('https://example.test/api/conversation', {
    method: 'OPTIONS',
    headers: { Origin: origin },
  }), { DB: fakeDatabase() });

  assert.equal(response.status, 204);
  assert.equal(response.headers.get('access-control-allow-origin'), origin);
  assert.match(response.headers.get('access-control-allow-methods'), /DELETE/);
});

test('conversation DELETE requires authentication', async () => {
  const response = await worker.fetch(new Request('https://example.test/api/conversation?id=12', {
    method: 'DELETE',
  }), { DB: fakeDatabase() });

  assert.equal(response.status, 401);
  assert.deepEqual(await response.json(), {
    ok: false,
    error: 'Authentication required.',
  });
});

test('authenticated conversation DELETE is explicit and audited', async () => {
  const db = fakeDatabase();
  const response = await worker.fetch(new Request('https://example.test/api/conversation?id=12', {
    method: 'DELETE',
    headers: {
      Authorization: 'Bearer test-token',
      Origin: origin,
      'CF-Connecting-IP': '192.0.2.10',
      'User-Agent': 'worker-contract-test',
    },
  }), { DB: db });

  assert.equal(response.status, 200);
  assert.deepEqual(await response.json(), {
    ok: true,
    data: { id: '12', deleted: true },
  });
  assert.equal(db.batches.length, 1);
  assert.equal(db.batches[0][0].values[1], 'conversation.delete');
  assert.match(db.batches[0][1].sql, /DELETE FROM conversations/);
});

test('email helper reports not configured without making a request', async () => {
  let called = false;
  const result = await sendReplyEmail({}, { contact_email: 'visitor@example.test' }, 'reply', async () => {
    called = true;
  });

  assert.deepEqual(result, { enabled: false, sent: false, status: 'not_configured' });
  assert.equal(called, false);
});

test('email helper sends only to the stored visitor address', async () => {
  let request;
  const result = await sendReplyEmail({
    BREVO_API_KEY: 'test-only-key',
    REPLY_FROM_EMAIL: 'verified@example.test',
    REPLY_FROM_NAME: 'Configured Sender',
  }, { contact_email: 'visitor@example.test', subject: 'Subject' }, 'Reply body', async (url, options) => {
    request = { url, options };
    return { ok: true, status: 201 };
  });

  assert.deepEqual(result, { enabled: true, sent: true, status: 'sent' });
  assert.equal(request.url, 'https://api.brevo.com/v3/smtp/email');
  assert.equal(request.options.method, 'POST');
  assert.equal(request.options.headers.accept, 'application/json');
  assert.equal(request.options.headers['api-key'], 'test-only-key');
  assert.equal(request.options.headers['content-type'], 'application/json');

  const payload = JSON.parse(request.options.body);
  assert.deepEqual(payload.sender, {
    email: 'verified@example.test',
    name: 'Configured Sender',
  });
  assert.deepEqual(payload.to, [{ email: 'visitor@example.test' }]);
  assert.equal(payload.subject, 'Subject');
  assert.equal(payload.textContent, 'Reply body');
});

test('email helper reports provider failures without exposing details', async () => {
  const result = await sendReplyEmail({
    BREVO_API_KEY: 'test-only-key',
    REPLY_FROM_EMAIL: 'verified@example.test',
  }, { contact_email: 'visitor@example.test' }, 'Reply body', async () => ({
    ok: false,
    status: 400,
  }));

  assert.deepEqual(result, { enabled: true, sent: false, status: 'provider_error' });
});

test('email helper reports network failures without exposing details', async () => {
  const result = await sendReplyEmail({
    BREVO_API_KEY: 'test-only-key',
    REPLY_FROM_EMAIL: 'verified@example.test',
  }, { contact_email: 'visitor@example.test' }, 'Reply body', async () => {
    throw new Error('synthetic provider failure');
  });

  assert.deepEqual(result, { enabled: true, sent: false, status: 'network_error' });
});

test('Worker source preserves reply notification and existing route contracts', () => {
  assert.match(source, /new_admin_reply/);
  assert.match(source, /INSERT INTO conversation_messages/);
  assert.match(source, /read_at = CURRENT_TIMESTAMP/);
  assert.match(source, /archived_at = CURRENT_TIMESTAMP/);
  assert.match(source, /SET updated_at = CURRENT_TIMESTAMP/);
  assert.match(source, /url\.pathname === '\/api\/contact'/);
  assert.match(source, /url\.pathname === '\/api\/auth\/login'/);
  assert.match(source, /url\.pathname === '\/api\/auth\/logout'/);
  assert.match(source, /url\.pathname === '\/api\/auth\/me'/);
  assert.match(source, /url\.pathname === '\/api\/conversations'/);
  assert.match(source, /url\.pathname === '\/api\/conversation'/);
});
