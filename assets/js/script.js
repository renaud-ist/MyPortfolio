document.documentElement.classList.add('js');

const prefersReducedMotion = window.matchMedia
  && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const supportsPointerLight = window.matchMedia
  && window.matchMedia('(hover: hover) and (pointer: fine)').matches;

if (!prefersReducedMotion && supportsPointerLight) {
  let pointerFrame = null;
  let pointerX = window.innerWidth * 0.5;
  let pointerY = window.innerHeight * 0.25;

  window.addEventListener('pointermove', (event) => {
    pointerX = event.clientX;
    pointerY = event.clientY;

    if (pointerFrame === null) {
      pointerFrame = window.requestAnimationFrame(() => {
        document.documentElement.style.setProperty('--pointer-x', `${pointerX}px`);
        document.documentElement.style.setProperty('--pointer-y', `${pointerY}px`);
        pointerFrame = null;
      });
    }
  }, { passive: true });
}

const revealItems = document.querySelectorAll('.reveal');

if ('IntersectionObserver' in window) {
  const revealObserver = new IntersectionObserver(
    (entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          entry.target.classList.add('visible');
          revealObserver.unobserve(entry.target);
        }
      });
    },
    { threshold: 0.12 }
  );

  revealItems.forEach((item) => revealObserver.observe(item));
} else {
  revealItems.forEach((item) => item.classList.add('visible'));
}

const countEls = document.querySelectorAll('[data-count]');

const filterButtons = document.querySelectorAll('[data-filter]');
const projectCards = document.querySelectorAll('.project-card[data-category]');

filterButtons.forEach((button) => {
  button.addEventListener('click', () => {
    const filter = button.dataset.filter;

    filterButtons.forEach((filterButton) => {
      const isActive = filterButton === button;
      filterButton.classList.toggle('active', isActive);
      filterButton.setAttribute('aria-pressed', String(isActive));
    });

    projectCards.forEach((card) => {
      const shouldShow = filter === 'all' || card.dataset.category === filter;
      card.hidden = !shouldShow;
    });
  });
});

const animateCount = (el) => {
  const target = Number(el.dataset.count);
  if (!Number.isFinite(target) || target < 0) {
    return;
  }

  const prefersReducedMotion = window.matchMedia
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  if (prefersReducedMotion) {
    el.textContent = target;
    return;
  }

  let start = 0;
  const duration = 1200;
  const stepTime = 30;
  const increment = Math.ceil(target / (duration / stepTime));

  const timer = setInterval(() => {
    start += increment;
    if (start >= target) {
      el.textContent = target;
      clearInterval(timer);
      return;
    }
    el.textContent = start;
  }, stepTime);
};

if ('IntersectionObserver' in window) {
  const countObserver = new IntersectionObserver(
    (entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          animateCount(entry.target);
          countObserver.unobserve(entry.target);
        }
      });
    },
    { threshold: 0.6 }
  );

  countEls.forEach((el) => countObserver.observe(el));
} else {
  countEls.forEach((el) => animateCount(el));
}

const form = document.getElementById('contactForm');
const statusBox = form ? form.querySelector('.form-status') : null;
const API_BASE_URL = (() => {
  const host = window.location.hostname;
  const isLocal = host === '127.0.0.1' || host === 'localhost';
  const isGitHubPages = host === 'renaud-ist.github.io';

  if (isLocal) {
    return 'http://127.0.0.1:8787';
  }

  if (isGitHubPages) {
    return 'https://myportfolio-api-proxy.yangdarenaud893.workers.dev';
  }

  return window.location.origin;
})();

const safeText = (value) => value == null ? '' : String(value).replace(/\s+/g, ' ').trim();

const mapApiError = (error, fallback) => {
  if (!error) {
    return fallback;
  }

  if (error.status === 401) {
    return 'Your session has expired. Please sign in again.';
  }
  if (error.status === 403) {
    return 'You are not allowed to perform this action.';
  }
  if (error.status === 404) {
    return 'The requested conversation could not be found.';
  }
  if (error.status === 409) {
    return 'This message is already in progress. Please try again.';
  }
  if (error.status === 429) {
    return 'Too many requests. Please wait a moment and try again.';
  }
  if (error.status >= 500) {
    return 'Something went wrong. Please try again later.';
  }

  const message = safeText(error.message || error.error || error.detail || fallback);
  return message || fallback;
};

const apiRequest = async (url, options = {}) => {
  const requestOptions = {
    ...options,
    headers: {
      Accept: 'application/json',
      ...(options.body && !(options.body instanceof FormData) ? { 'Content-Type': 'application/json' } : {}),
      ...(options.headers || {}),
    },
  };

  const response = await fetch(url, requestOptions);

  let payload = null;
  try {
    payload = await response.json();
  } catch {
    payload = null;
  }

  if (!response.ok) {
    const message = payload && (payload.error || payload.message || payload.details);
    const error = new Error(message || 'Request failed.');
    error.status = response.status;
    error.payload = payload;
    throw error;
  }

  return payload;
};

if (form) {
  form.addEventListener('submit', async (event) => {
    event.preventDefault();

    const formData = new FormData(form);
    const submitButton = form.querySelector('button[type="submit"]');
    const name = safeText(formData.get('name'));
    const email = safeText(formData.get('email'));
    const subject = safeText(formData.get('subject'));
    const message = safeText(formData.get('message'));
    const website = safeText(formData.get('website'));

    if (website) {
      return;
    }

    if (!name || !email || !message) {
      statusBox.classList.add('error');
      statusBox.textContent = 'Please enter your name, email, and message.';
      return;
    }

    submitButton.disabled = true;
    submitButton.textContent = 'Sending...';
    statusBox.classList.remove('error');
    statusBox.textContent = 'Sending your message...';

    try {
      const payload = {
        name,
        email,
        subject,
        message,
      };

      const result = await apiRequest(`${API_BASE_URL}/api/contact`, {
        method: 'POST',
        body: JSON.stringify(payload),
      });

      if (!result || result.ok !== true) {
        throw new Error(result && (result.message || 'Message submission failed.'));
      }

      statusBox.classList.remove('error');
      statusBox.classList.add('success');
      statusBox.textContent = result.message || 'Your message has been successfully sent.';
      form.reset();
    } catch (error) {
      console.error('Contact form submission failed:', error);
      statusBox.classList.add('error');
      statusBox.textContent = mapApiError(error, 'Something went wrong. Please try again.');
    } finally {
      submitButton.disabled = false;
      submitButton.textContent = 'Send message';
    }
  });
}

const adminPanel = document.getElementById('admin-panel');
const adminToggle = document.getElementById('adminToggle');
const adminLoginSection = document.getElementById('adminLoginSection');
const adminDashboardSection = document.getElementById('adminDashboardSection');
const adminLoginForm = document.getElementById('adminLoginForm');
const adminLoginStatus = document.getElementById('adminLoginStatus');
const adminPassword = document.getElementById('adminPassword');
const adminPasswordToggle = document.getElementById('adminPasswordToggle');
const adminLogoutBtn = document.getElementById('adminLogoutBtn');
const notificationBell = document.getElementById('notificationBell');
const notificationCount = document.getElementById('notificationCount');
const inboxNavCount = document.getElementById('inboxNavCount');
const notificationsNavCount = document.getElementById('notificationsNavCount');

const adminInboxList = document.getElementById('adminInboxList');
const adminArchivedList = document.getElementById('adminArchivedList');
const adminSearchInput = document.getElementById('adminSearchInput');
const adminSearchResults = document.getElementById('adminSearchResults');

const adminConversationDetail = document.getElementById('adminConversationDetail');
const notificationList = document.getElementById('notificationList');

const inboxStatus = document.getElementById('inboxStatus');
const archivedStatus = document.getElementById('archivedStatus');
const notificationsStatus = document.getElementById('notificationsStatus');
const searchStatus = document.getElementById('searchStatus');

const storageKey = 'portfolio_admin_session';

const adminState = {
  token: null,
  user: null,
  notifications: [],
  conversations: [],
  archivedConversations: [],
  searchResults: [],
  conversation: null,
  selectedConversationId: null,
  currentView: 'inbox',
  pollingHandle: null,
  currentlyLoading: false,
};

const setStatus = (element, text, type = '') => {
  if (!element) return;

  element.classList.remove('error', 'success');

  if (type) {
    element.classList.add(type);
  }

  element.textContent = text;
};

const readStoredSession = () => {
  try {
    const raw = localStorage.getItem(storageKey);
    return raw ? JSON.parse(raw) : null;
  } catch {
    return null;
  }
};

const saveStoredSession = (session) => {
  if (!session) {
    localStorage.removeItem(storageKey);
    return;
  }

  localStorage.setItem(storageKey, JSON.stringify(session));
};


const escapeHtml = (value = '') => safeText(value)
  .replace(/&/g, '&amp;')
  .replace(/</g, '&lt;')
  .replace(/>/g, '&gt;')
  .replace(/"/g, '&quot;')
  .replace(/'/g, '&#039;');

const formatTime = (value) => {
  if (!value) return 'Unknown time';

  const date = new Date(value);

  if (!Number.isNaN(date.getTime())) {
    return date.toLocaleString([], {
      dateStyle: 'medium',
      timeStyle: 'short',
    });
  }

  return String(value);
};

const getResponseData = (result) => {
  if (!result || typeof result !== 'object') {
    return result;
  }

  if (result.data && typeof result.data === 'object') {
    return result.data;
  }

  return result;
};

const persistAdminSession = () => {
  saveStoredSession({
    token: adminState.token,
    user: adminState.user,
  });
};

const clearAdminSession = () => {
  adminState.token = null;
  adminState.user = null;
  adminState.notifications = [];
  adminState.conversations = [];
  adminState.archivedConversations = [];
  adminState.searchResults = [];
  adminState.conversation = null;
  adminState.selectedConversationId = null;

  saveStoredSession(null);

  if (adminState.pollingHandle) {
    clearInterval(adminState.pollingHandle);
    adminState.pollingHandle = null;
  }

  if (adminPanel) adminPanel.hidden = true;


  // Clear login credentials and restore the password control to its initial state.
  if (adminLoginForm) {
    adminLoginForm.reset();
  }

  if (adminPassword) {
    adminPassword.type = 'password';
  }

  if (adminPasswordToggle) {
    adminPasswordToggle.setAttribute('aria-label', 'Show password');
    adminPasswordToggle.setAttribute('title', 'Show password');

    const icon = adminPasswordToggle.querySelector('span');
    if (icon) {
      icon.textContent = '👁';
    }
  }

  if (adminLoginStatus) {
    adminLoginStatus.textContent = '';
    adminLoginStatus.classList.remove('error', 'success');
  }


  renderAdminState();
  renderConversation();
  renderConversationList();
  renderArchivedList();
  renderNotifications();
  renderSearchResults();
};

const renderNotificationCount = () => {
  const count = adminState.notifications.length;

  if (notificationCount) {
    notificationCount.textContent = String(count);
    notificationCount.hidden = count === 0;
  }

  if (notificationsNavCount) {
    notificationsNavCount.textContent = String(count);
    notificationsNavCount.hidden = count === 0;
  }
};

const renderAdminState = () => {
  const authenticated = Boolean(adminState.token && adminState.user);

  if (adminLoginSection) adminLoginSection.hidden = authenticated;
  if (adminDashboardSection) adminDashboardSection.hidden = !authenticated;
  if (adminLogoutBtn) adminLogoutBtn.hidden = !authenticated;

  const username = adminState.user?.username || adminState.user?.name || '—';
  const email = adminState.user?.email || '—';
  const role = adminState.user?.role || '—';

  const usernameElement = document.getElementById('adminAccountUsername');
  const emailElement = document.getElementById('adminAccountEmail');
  const roleElement = document.getElementById('adminAccountRole');

  if (usernameElement) usernameElement.textContent = username;
  if (emailElement) emailElement.textContent = email;
  if (roleElement) roleElement.textContent = role;
};

const showAdminView = (view) => {
  const allowedViews = [
    'inbox',
    'conversation',
    'notifications',
    'archived',
    'search',
    'account',
  ];

  const nextView = allowedViews.includes(view) ? view : 'inbox';
  adminState.currentView = nextView;

  document.querySelectorAll('[data-admin-view-panel]').forEach((panel) => {
    panel.hidden = panel.getAttribute('data-admin-view-panel') !== nextView;
  });

  document.querySelectorAll('[data-admin-view]').forEach((button) => {
    button.classList.toggle(
      'is-active',
      button.getAttribute('data-admin-view') === nextView
    );
  });
};

const renderConversationCard = (conversation, archived = false) => {
  const item = getResponseData(conversation) || {};
  const id = item.id || item.conversation_id;

  return `
    <article class="admin-conversation-card">
      <div class="admin-conversation-card-header">
        <div>
          <h5 class="admin-conversation-card-subject">
            ${escapeHtml(item.subject || 'No subject')}
          </h5>
          <p class="admin-conversation-card-preview">
            ${escapeHtml(item.latest_message_preview || item.message_preview || 'No message preview available.')}
          </p>
        </div>

        <span class="admin-conversation-card-status">
          ${archived ? 'Archived' : (item.is_read ? 'Read' : 'Unread')}
        </span>
      </div>

      <div class="admin-conversation-card-meta">
        <span>${escapeHtml(item.contact_name || 'Unknown visitor')}</span>
        <span>${escapeHtml(item.contact_email || '')}</span>
        <span>${escapeHtml(formatTime(item.last_message_at || item.updated_at || item.created_at))}</span>
      </div>

      <div class="conversation-control-row">
        <button
          type="button"
          class="button button-primary admin-conversation-open"
          data-open-conversation="${escapeHtml(id || '')}"
        >
          Open
        </button>

        ${
          archived
            ? `
              <button
                type="button"
                class="button button-secondary"
                data-restore-conversation="${escapeHtml(id || '')}"
              >
                Restore
              </button>
            `
            : `
              <button
                type="button"
                class="button button-secondary"
                data-toggle-read="${escapeHtml(id || '')}"
                data-next-read="${item.is_read ? 'false' : 'true'}"
              >
                ${item.is_read ? 'Mark unread' : 'Mark read'}
              </button>

              <button
                type="button"
                class="button button-secondary"
                data-archive-conversation="${escapeHtml(id || '')}"
              >
                Archive
              </button>
            `
        }

        <button
          type="button"
          class="button button-danger"
          data-delete-conversation="${escapeHtml(id || '')}"
        >
          Delete
        </button>
      </div>
    </article>
  `;
};

const attachConversationListActions = (container) => {
  if (!container) return;

  container.querySelectorAll('[data-open-conversation]').forEach((button) => {
    button.addEventListener('click', async () => {
      const id = button.getAttribute('data-open-conversation');
      if (!id) return;

      const loaded = await loadConversation(id);
      if (loaded) {
        showAdminView('conversation');
      }
    });
  });

  container.querySelectorAll('[data-toggle-read]').forEach((button) => {
    button.addEventListener('click', async () => {
      const id = button.getAttribute('data-toggle-read');
      const nextRead = button.getAttribute('data-next-read') === 'true';

      if (id) {
        await updateConversation(id, { is_read: nextRead });
      }
    });
  });

  container.querySelectorAll('[data-archive-conversation]').forEach((button) => {
    button.addEventListener('click', async () => {
      const id = button.getAttribute('data-archive-conversation');

      if (id) {
        await updateConversation(id, { is_archived: true });
      }
    });
  });

  container.querySelectorAll('[data-restore-conversation]').forEach((button) => {
    button.addEventListener('click', async () => {
      const id = button.getAttribute('data-restore-conversation');

      if (id) {
        await updateConversation(id, { is_archived: false });
      }
    });
  });

  container.querySelectorAll('[data-delete-conversation]').forEach((button) => {
    button.addEventListener('click', async () => {
      const id = button.getAttribute('data-delete-conversation');

      if (id) {
        await deleteConversation(id);
      }
    });
  });
};

const renderConversationList = () => {
  if (!adminInboxList) return;

  inboxNavCount.textContent = String(adminState.conversations.length);

  if (adminState.conversations.length === 0) {
    adminInboxList.innerHTML = `
      <div class="conversation-empty">
        <h4>Inbox is empty</h4>
        <p>No active conversations were found.</p>
      </div>
    `;
    return;
  }

  adminInboxList.innerHTML = adminState.conversations
    .map((item) => renderConversationCard(item, false))
    .join('');

  attachConversationListActions(adminInboxList);
};

const renderArchivedList = () => {
  if (!adminArchivedList) return;

  if (adminState.archivedConversations.length === 0) {
    adminArchivedList.innerHTML = `
      <div class="conversation-empty">
        <h4>No archived conversations</h4>
        <p>Archived conversations will appear here.</p>
      </div>
    `;
    return;
  }

  adminArchivedList.innerHTML = adminState.archivedConversations
    .map((item) => renderConversationCard(item, true))
    .join('');

  attachConversationListActions(adminArchivedList);
};

const loadConversations = async (archived = false) => {
  if (!adminState.token) return false;

  const statusElement = archived ? archivedStatus : inboxStatus;
  const target = archived ? adminArchivedList : adminInboxList;

  setStatus(statusElement, 'Loading conversations...');

  try {
    const query = new URLSearchParams({
      page: '1',
      per_page: '100',
      is_archived: archived ? '1' : '0',
    });

    const result = await apiRequest(
      `${API_BASE_URL}/api/conversations?${query.toString()}`,
      {
        headers: {
          Authorization: `Bearer ${adminState.token}`,
        },
      }
    );

    if (!result || !result.ok) {
      throw new Error(result?.error || 'Unable to load conversations.');
    }

    const data = getResponseData(result);
    const items = Array.isArray(data)
      ? data
      : Array.isArray(data?.conversations)
        ? data.conversations
        : [];

    if (archived) {
      adminState.archivedConversations = items;
      renderArchivedList();
    } else {
      adminState.conversations = items;
      renderConversationList();
    }

    setStatus(
      statusElement,
      `${items.length} conversation${items.length === 1 ? '' : 's'} loaded.`,
      'success'
    );

    return true;
  } catch (error) {
    console.error('Conversation list load failed:', error);

    setStatus(
      statusElement,
      mapApiError(error, 'Unable to load conversations.'),
      'error'
    );

    if (target) {
      target.innerHTML = `
        <div class="conversation-empty">
          <h4>Unable to load conversations</h4>
          <p>${escapeHtml(mapApiError(error, 'Please try again.'))}</p>
        </div>
      `;
    }

    return false;
  }
};

const renderSearchResults = () => {
  if (!adminSearchResults) return;

  const query = safeText(adminSearchInput?.value).toLowerCase();

  if (!query) {
    adminState.searchResults = [];
    adminSearchResults.innerHTML = `
      <div class="conversation-empty">
        <h4>Search conversations</h4>
        <p>Enter a name, email, subject, or message preview.</p>
      </div>
    `;
    setStatus(searchStatus, '');
    return;
  }

  const all = [
    ...adminState.conversations,
    ...adminState.archivedConversations,
  ];

  adminState.searchResults = all.filter((item) => {
    const haystack = [
      item.contact_name,
      item.contact_email,
      item.subject,
      item.latest_message_preview,
      item.message_preview,
    ]
      .map((value) => safeText(value).toLowerCase())
      .join(' ');

    return haystack.includes(query);
  });

  if (adminState.searchResults.length === 0) {
    adminSearchResults.innerHTML = `
      <div class="conversation-empty">
        <h4>No matches</h4>
        <p>No loaded conversation matches “${escapeHtml(query)}”.</p>
      </div>
    `;
  } else {
    adminSearchResults.innerHTML = adminState.searchResults
      .map((item) => renderConversationCard(item, Boolean(item.is_archived)))
      .join('');

    attachConversationListActions(adminSearchResults);
  }

  setStatus(
    searchStatus,
    `${adminState.searchResults.length} matching conversation${adminState.searchResults.length === 1 ? '' : 's'}.`,
    'success'
  );
};

const loadConversation = async (conversationId, refreshNotifications = true) => {
  if (!conversationId || !adminState.token) return false;

  try {
    const result = await apiRequest(
      `${API_BASE_URL}/api/conversation?id=${encodeURIComponent(conversationId)}`,
      {
        headers: {
          Authorization: `Bearer ${adminState.token}`,
        },
      }
    );

    if (!result || !result.ok) {
      throw new Error(result?.error || 'Conversation unavailable.');
    }

    adminState.selectedConversationId = String(conversationId);
    adminState.conversation = getResponseData(result);

    renderConversation();

    if (refreshNotifications) {
      await fetchNotifications();
    }

    return true;
  } catch (error) {
    console.error('Conversation load failed:', error);

    setStatus(
      notificationsStatus,
      mapApiError(error, 'The selected conversation is unavailable.'),
      'error'
    );

    return false;
  }
};

const renderConversation = () => {
  if (!adminConversationDetail) return;

  if (!adminState.selectedConversationId || !adminState.conversation) {
    adminConversationDetail.innerHTML = `
      <div class="conversation-empty">
        <h4>No conversation selected</h4>
        <p>Open a conversation from the Inbox to view the full message thread.</p>
      </div>
    `;
    return;
  }

  const conversation = getResponseData(adminState.conversation) || {};
  const messages = Array.isArray(conversation.messages)
    ? conversation.messages
    : [];

  const renderMessage = (message) => {
    const senderType = message.sender_type === 'admin' ? 'admin' : 'visitor';
    const senderLabel = senderType === 'admin' ? 'Admin reply' : 'Visitor message';

    return `
      <article class="conversation-message ${senderType}">
        <div class="conversation-message-header">
          <strong>${senderLabel}</strong>
          <span>${escapeHtml(message.sender_name || '')}</span>
          <span>${escapeHtml(formatTime(message.created_at))}</span>
        </div>
        <div class="conversation-message-body">
          ${escapeHtml(message.body || '').replace(/\n/g, '<br>')}
        </div>
      </article>
    `;
  };

  const readState = Boolean(conversation.is_read);
  const archivedState = Boolean(conversation.is_archived);

  adminConversationDetail.innerHTML = `
    <div class="conversation-shell">
      <div class="conversation-summary">
        <div class="conversation-summary-header">
          <div>
            <span class="conversation-status-badge">
              ${escapeHtml(conversation.status || 'open')}
            </span>
            ${
              archivedState
                ? '<span class="conversation-status-badge">Archived</span>'
                : ''
            }
          </div>
        </div>

        <div class="conversation-summary-grid">
          <div>
            <strong>Visitor</strong>
            <br>
            ${escapeHtml(conversation.contact_name || 'Unknown visitor')}
            <br>
            ${escapeHtml(conversation.contact_email || '')}
          </div>

          <div>
            <strong>Subject</strong>
            <br>
            ${escapeHtml(conversation.subject || 'No subject')}
          </div>

          <div>
            <strong>Created</strong>
            <br>
            ${escapeHtml(formatTime(conversation.created_at))}
          </div>

          <div>
            <strong>Last update</strong>
            <br>
            ${escapeHtml(formatTime(conversation.last_message_at || conversation.updated_at))}
          </div>
        </div>
      </div>

      <div class="conversation-control-row">
        <button type="button" id="conversationReadBtn" class="button button-secondary">
          ${readState ? 'Mark unread' : 'Mark read'}
        </button>

        ${
          archivedState
            ? `
              <button type="button" id="conversationArchiveBtn" class="button button-secondary">
                Restore
              </button>
            `
            : `
              <button type="button" id="conversationArchiveBtn" class="button button-secondary">
                Archive
              </button>
            `
        }

        <button type="button" id="conversationDeleteBtn" class="button button-danger">
          Delete conversation
        </button>
      </div>

      <div class="conversation-thread">
        ${
          messages.length
            ? messages.map(renderMessage).join('')
            : '<p class="inline-status">No messages available.</p>'
        }
      </div>

      <div class="reply-box">
        <label>
          <span>Reply</span>
          <textarea
            id="adminReplyInput"
            placeholder="Write a response to the visitor..."
            maxlength="65535"
          ></textarea>
        </label>

        <div class="reply-actions">
          <button type="button" id="sendReplyBtn" class="button button-primary">
            Reply
          </button>
          <p id="replyStatus" class="inline-status" aria-live="polite"></p>
        </div>
      </div>
    </div>
  `;

  document.getElementById('conversationReadBtn')?.addEventListener(
    'click',
    () => updateConversation(conversation.id, { is_read: !readState })
  );

  document.getElementById('conversationArchiveBtn')?.addEventListener(
    'click',
    () => updateConversation(
      conversation.id,
      { is_archived: !archivedState }
    )
  );

  document.getElementById('conversationDeleteBtn')?.addEventListener(
    'click',
    () => deleteConversation(conversation.id)
  );

  document.getElementById('sendReplyBtn')?.addEventListener(
    'click',
    () => sendReply(conversation.id)
  );
};

const updateConversation = async (conversationId, changes) => {
  if (!conversationId || !adminState.token) return false;

  try {
    const result = await apiRequest(
      `${API_BASE_URL}/api/conversation?id=${encodeURIComponent(conversationId)}`,
      {
        method: 'PATCH',
        headers: {
          Authorization: `Bearer ${adminState.token}`,
        },
        body: JSON.stringify(changes),
      }
    );

    if (!result || !result.ok) {
      throw new Error(result?.error || 'Unable to update conversation.');
    }

    await Promise.all([
      loadConversations(false),
      loadConversations(true),
      fetchNotifications(),
    ]);

    if (
      adminState.selectedConversationId &&
      String(adminState.selectedConversationId) === String(conversationId)
    ) {
      await loadConversation(conversationId, false);
    }

    return true;
  } catch (error) {
    console.error('Conversation update failed:', error);

    setStatus(
      adminState.currentView === 'archived'
        ? archivedStatus
        : inboxStatus,
      mapApiError(error, 'Unable to update conversation.'),
      'error'
    );

    return false;
  }
};

const deleteConversation = async (conversationId) => {
  if (!conversationId || !adminState.token) return false;

  const confirmed = window.confirm(
    'Delete this conversation permanently? This action cannot be undone.'
  );

  if (!confirmed) return false;

  try {
    const result = await apiRequest(
      `${API_BASE_URL}/api/conversation?id=${encodeURIComponent(conversationId)}`,
      {
        method: 'DELETE',
        headers: {
          Authorization: `Bearer ${adminState.token}`,
        },
      }
    );

    if (!result || !result.ok) {
      throw new Error(result?.error || 'Unable to delete conversation.');
    }

    if (
      adminState.selectedConversationId &&
      String(adminState.selectedConversationId) === String(conversationId)
    ) {
      adminState.selectedConversationId = null;
      adminState.conversation = null;
      showAdminView('inbox');
    }

    await Promise.all([
      loadConversations(false),
      loadConversations(true),
      fetchNotifications(),
    ]);

    return true;
  } catch (error) {
    console.error('Conversation delete failed:', error);

    setStatus(
      inboxStatus,
      mapApiError(error, 'Unable to delete conversation.'),
      'error'
    );

    return false;
  }
};

const sendReply = async (conversationId) => {
  const replyInput = document.getElementById('adminReplyInput');
  const replyButton = document.getElementById('sendReplyBtn');
  const replyStatus = document.getElementById('replyStatus');

  if (!replyInput || !replyButton || !conversationId) return;

  const body = safeText(replyInput.value);

  if (!body) {
    setStatus(replyStatus, 'Please enter a reply before sending.', 'error');
    replyInput.focus();
    return;
  }

  if (body.length > 65535) {
    setStatus(replyStatus, 'Reply is too long.', 'error');
    return;
  }

  replyButton.disabled = true;
  setStatus(replyStatus, 'Sending reply...');

  try {
    const result = await apiRequest(
      `${API_BASE_URL}/api/conversation/reply?id=${encodeURIComponent(conversationId)}`,
      {
        method: 'POST',
        headers: {
          Authorization: `Bearer ${adminState.token}`,
        },
        body: JSON.stringify({ body }),
      }
    );

    if (!result || !result.ok) {
      throw new Error(result?.error || 'Reply failed.');
    }

    replyInput.value = '';

    await loadConversation(conversationId, false);

    await Promise.all([
      loadConversations(false),
      fetchNotifications(),
    ]);

    setStatus(replyStatus, 'Reply sent successfully.', 'success');
  } catch (error) {
    console.error('Reply failed:', error);

    setStatus(
      replyStatus,
      mapApiError(error, 'The reply could not be sent.'),
      'error'
    );
  } finally {
    replyButton.disabled = false;
  }
};

const renderNotifications = () => {
  if (!notificationList) return;

  if (adminState.notifications.length === 0) {
    notificationList.innerHTML = `
      <li class="notification-empty">
        No notifications yet.
      </li>
    `;
    renderNotificationCount();
    return;
  }

  notificationList.innerHTML = adminState.notifications.map((item) => `
    <li class="notification-item">
      <div>
        <strong>${escapeHtml(item.contact_name || 'Visitor enquiry')}</strong>
        <span class="notification-item-meta">
          ${escapeHtml(item.subject || 'No subject')}
        </span>
        <span class="notification-item-meta">
          ${escapeHtml(item.notification_type || 'Notification')}
          ·
          ${escapeHtml(formatTime(item.created_at || item.sent_at))}
        </span>
      </div>

      <div class="conversation-control-row">
        <button
          type="button"
          class="button button-primary"
          data-notification-open="${escapeHtml(item.conversation_id || '')}"
        >
          Open
        </button>

        <button
          type="button"
          class="button button-danger"
          data-notification-delete="${escapeHtml(item.id || '')}"
        >
          Delete
        </button>
      </div>
    </li>
  `).join('');

  notificationList.querySelectorAll('[data-notification-open]').forEach(
    (button) => {
      button.addEventListener('click', async () => {
        const id = button.getAttribute('data-notification-open');

        if (!id) return;

        if (await loadConversation(id)) {
          showAdminView('conversation');
        }
      });
    }
  );

  notificationList.querySelectorAll('[data-notification-delete]').forEach(
    (button) => {
      button.addEventListener('click', async () => {
        const id = button.getAttribute('data-notification-delete');

        if (id) {
          await deleteNotification(id);
        }
      });
    }
  );

  renderNotificationCount();
};

const fetchNotifications = async () => {
  if (!adminState.token) return false;

  setStatus(notificationsStatus, 'Loading notifications...');

  try {
    const result = await apiRequest(
      `${API_BASE_URL}/api/notifications?page=1&per_page=100`,
      {
        headers: {
          Authorization: `Bearer ${adminState.token}`,
        },
      }
    );

    if (!result || !result.ok) {
      throw new Error(result?.error || 'Unable to load notifications.');
    }

    const data = getResponseData(result);

    adminState.notifications = Array.isArray(data)
      ? data
      : Array.isArray(data?.notifications)
        ? data.notifications
        : [];

    renderNotifications();

    setStatus(
      notificationsStatus,
      `${adminState.notifications.length} notification${adminState.notifications.length === 1 ? '' : 's'} loaded.`,
      'success'
    );

    return true;
  } catch (error) {
    console.error('Failed to fetch notifications:', error);

    setStatus(
      notificationsStatus,
      mapApiError(error, 'Unable to load notifications.'),
      'error'
    );

    return false;
  }
};

const deleteNotification = async (notificationId) => {
  if (!notificationId || !adminState.token) return false;

  try {
    const result = await apiRequest(
      `${API_BASE_URL}/api/notifications?id=${encodeURIComponent(notificationId)}`,
      {
        method: 'DELETE',
        headers: {
          Authorization: `Bearer ${adminState.token}`,
        },
      }
    );

    if (!result || !result.ok) {
      throw new Error(result?.error || 'Unable to delete notification.');
    }

    await fetchNotifications();
    return true;
  } catch (error) {
    console.error('Notification delete failed:', error);

    setStatus(
      notificationsStatus,
      mapApiError(error, 'Unable to delete notification.'),
      'error'
    );

    return false;
  }
};

const refreshCurrentView = async () => {
  if (adminState.currentView === 'inbox') {
    return loadConversations(false);
  }

  if (adminState.currentView === 'archived') {
    return loadConversations(true);
  }

  if (adminState.currentView === 'notifications') {
    return fetchNotifications();
  }

  if (adminState.currentView === 'search') {
    await Promise.all([
      loadConversations(false),
      loadConversations(true),
    ]);
    renderSearchResults();
    return true;
  }

  if (adminState.currentView === 'conversation' && adminState.selectedConversationId) {
    return loadConversation(adminState.selectedConversationId, false);
  }

  return true;
};

if (adminPasswordToggle && adminPassword) {
  adminPasswordToggle.addEventListener('click', () => {
    const showing = adminPassword.type === 'text';

    adminPassword.type = showing ? 'password' : 'text';

    adminPasswordToggle.setAttribute(
      'aria-label',
      showing ? 'Show password' : 'Hide password'
    );

    adminPasswordToggle.setAttribute(
      'title',
      showing ? 'Show password' : 'Hide password'
    );

    const icon = adminPasswordToggle.querySelector('span');

    if (icon) {
      icon.textContent = '👁';
    }
  });
}

document.querySelectorAll('[data-admin-view]').forEach((button) => {
  button.addEventListener('click', async () => {
    if (!adminState.token) return;

    const view = button.getAttribute('data-admin-view');

    showAdminView(view);

    if (view === 'inbox') {
      await loadConversations(false);
    } else if (view === 'archived') {
      await loadConversations(true);
    } else if (view === 'notifications') {
      await fetchNotifications();
    } else if (view === 'search') {
      renderSearchResults();
    } else if (view === 'conversation') {
      renderConversation();
    } else if (view === 'account') {
      renderAdminState();
    }
  });
});

document.getElementById('refreshInboxBtn')?.addEventListener(
  'click',
  () => loadConversations(false)
);

document.getElementById('refreshArchivedBtn')?.addEventListener(
  'click',
  () => loadConversations(true)
);

document.getElementById('refreshNotificationsBtn')?.addEventListener(
  'click',
  () => fetchNotifications()
);

document.getElementById('conversationBackBtn')?.addEventListener(
  'click',
  async () => {
    showAdminView('inbox');
    await loadConversations(false);
  }
);

document.getElementById('clearSearchBtn')?.addEventListener(
  'click',
  () => {
    if (adminSearchInput) adminSearchInput.value = '';
    renderSearchResults();
    adminSearchInput?.focus();
  }
);

adminSearchInput?.addEventListener('input', () => {
  renderSearchResults();
});

document.getElementById('adminAccountLogoutBtn')?.addEventListener(
  'click',
  () => {
    adminLogoutBtn?.click();
  }
);

if (adminToggle) {
  adminToggle.addEventListener('click', () => {
    const authenticated = Boolean(adminState.token && adminState.user);

    if (adminPanel) adminPanel.hidden = false;

    if (!authenticated) {
      if (adminLoginSection) adminLoginSection.hidden = false;
      if (adminDashboardSection) adminDashboardSection.hidden = true;
      adminLoginForm?.querySelector('input')?.focus();
      return;
    }

    renderAdminState();
    showAdminView(adminState.currentView || 'inbox');

    loadConversations(false);
    fetchNotifications();
  });
}

if (notificationBell) {
  notificationBell.addEventListener('click', () => {
    if (!adminState.token) {
      if (adminPanel) adminPanel.hidden = false;
      if (adminLoginSection) adminLoginSection.hidden = false;
      adminLoginForm?.querySelector('input')?.focus();
      return;
    }

    if (adminPanel) adminPanel.hidden = false;

    renderAdminState();
    showAdminView('notifications');
    fetchNotifications();
  });
}

if (adminLoginForm) {
  adminLoginForm.addEventListener('submit', async (event) => {
    event.preventDefault();

    const formData = new FormData(adminLoginForm);
    const email = safeText(formData.get('email'));
    const password = safeText(formData.get('password'));

    if (!email || !password) {
      setStatus(
        adminLoginStatus,
        'Please provide both email and password.',
        'error'
      );
      return;
    }

    const submitButton = adminLoginForm.querySelector('button[type="submit"]');

    submitButton.disabled = true;
    setStatus(adminLoginStatus, 'Signing in...');

    try {
      const result = await apiRequest(`${API_BASE_URL}/api/auth/login`, {
        method: 'POST',
        body: JSON.stringify({ email, password }),
      });

      if (!result || !result.ok || !result.token) {
        throw new Error(result?.error || 'Login failed.');
      }

      adminState.token = result.token;
      adminState.user = result.admin || null;

      persistAdminSession();
      renderAdminState();

      if (adminPanel) adminPanel.hidden = false;
      if (adminDashboardSection) adminDashboardSection.hidden = false;

      // Authentication succeeded. Show the Admin Inbox immediately.
      // Background data-loading failures must not prevent the dashboard from appearing.
      showAdminView('inbox');

      try {
        await Promise.all([
          loadConversations(false),
          loadConversations(true),
          fetchNotifications(),
        ]);
      } catch (loadError) {
        console.error('Admin data loading failed after successful login:', loadError);
      }

      setStatus(
        adminLoginStatus,
        'Signed in successfully.',
        'success'
      );
    } catch (error) {
      console.error('Admin login failed:', error);

      setStatus(
        adminLoginStatus,
        mapApiError(error, 'Unable to sign in. Please try again.'),
        'error'
      );
    } finally {
      submitButton.disabled = false;
    }
  });
}

if (adminLogoutBtn) {
  adminLogoutBtn.addEventListener('click', async () => {
    if (!adminState.token) {
      clearAdminSession();
      return;
    }

    try {
      await apiRequest(`${API_BASE_URL}/api/auth/logout`, {
        method: 'POST',
        headers: {
          Authorization: `Bearer ${adminState.token}`,
        },
      });
    } catch (error) {
      console.warn('Logout request failed:', error);
    } finally {
      clearAdminSession();
    }
  });
}

if (adminPanel) {
  adminPanel.hidden = true;
}

const initializeAdmin = () => {
  const savedSession = readStoredSession();

  if (savedSession && savedSession.token && savedSession.user) {
    adminState.token = savedSession.token;
    adminState.user = savedSession.user;

    if (adminPanel) adminPanel.hidden = false;

    renderAdminState();
    showAdminView('inbox');

    Promise.all([
      loadConversations(false),
      loadConversations(true),
      fetchNotifications(),
    ]);
  } else {
    renderAdminState();
  }
};

initializeAdmin();
renderAdminState();
renderConversationList();
renderArchivedList();
renderSearchResults();
renderConversation();
renderNotifications();
renderNotificationCount();
showAdminView('inbox');