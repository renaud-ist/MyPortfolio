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
const statusBox = document.querySelector('.form-status');
const API_BASE_URL = (() => {
  const host = window.location.hostname;
  const isLocal = host === '127.0.0.1' || host === 'localhost';
  const isGitHubPages = host === 'renaud-ist.github.io';

  if (isLocal) {
    return 'http://127.0.0.1:8090';
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

      const result = await apiRequest('https://myportfolio-api-proxy.yangdarenaud893.workers.dev/api/contact', {
        method: 'POST',
        body: JSON.stringify(payload),
      });

      if (!result || result.ok !== true) {
        throw new Error(result && (result.message || 'Message submission failed.'));
      }

      statusBox.classList.remove('error');
      statusBox.textContent = result.message || 'Thanks! Your message has been sent successfully.';
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
const adminLogoutBtn = document.getElementById('adminLogoutBtn');
const notificationBell = document.getElementById('notificationBell');
const notificationDropdown = document.getElementById('notificationDropdown');
const notificationList = document.getElementById('notificationList');
const notificationCount = document.getElementById('notificationCount');
const conversationPanel = document.getElementById('conversationPanel');

const storageKey = 'portfolio_admin_session';
const adminState = {
  token: null,
  user: null,
  notifications: [],
  selectedConversationId: null,
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

const clearAdminSession = () => {
  adminState.token = null;
  adminState.user = null;
  adminState.notifications = [];
  adminState.selectedConversationId = null;
  saveStoredSession(null);
  if (adminState.pollingHandle) {
    clearInterval(adminState.pollingHandle);
    adminState.pollingHandle = null;
  }
  renderAdminState();
  renderConversation();
};

const persistAdminSession = () => {
  saveStoredSession({
    token: adminState.token,
    user: adminState.user,
  });
};

const formatTime = (value) => {
  if (!value) {
    return 'Unknown time';
  }

  const date = new Date(value);
  if (!Number.isNaN(date.getTime())) {
    return date.toLocaleString([], {
      dateStyle: 'medium',
      timeStyle: 'short',
    });
  }

  return value;
};

const renderNotificationCount = () => {
  const count = adminState.notifications.filter((item) => item && item.status === 'pending').length;
  notificationCount.textContent = String(count);
  notificationCount.hidden = count === 0;
};

const renderAdminState = () => {
  const isAuthenticated = Boolean(adminState.token && adminState.user);
  adminLoginSection.hidden = isAuthenticated;
  adminDashboardSection.hidden = !isAuthenticated;
  adminLogoutBtn.hidden = !isAuthenticated;
  adminLogoutBtn.classList.toggle('visible', isAuthenticated);
  notificationBell.setAttribute('aria-expanded', String(notificationDropdown.classList.contains('is-open')));
  if (!isAuthenticated) {
    notificationDropdown.classList.remove('is-open');
    notificationList.innerHTML = '';
    renderNotificationCount();
  }
};

const renderConversation = () => {
  if (!adminState.selectedConversationId) {
    conversationPanel.innerHTML = `
      <div class="conversation-empty">
        <h4>No conversation selected</h4>
        <p>Select a notification from the inbox to view the full message thread.</p>
      </div>
    `;
    return;
  }

  const conversation = adminState.conversation;
  if (!conversation) {
    conversationPanel.innerHTML = `
      <div class="conversation-empty">
        <h4>Conversation unavailable</h4>
        <p>The selected conversation could not be loaded right now.</p>
      </div>
    `;
    return;
  }

  const summary = conversation.data || conversation;
  const messageItems = Array.isArray(summary.messages) ? summary.messages : [];
  const renderMessage = (message) => {
    const senderType = message.sender_type === 'admin' ? 'admin' : 'visitor';
    const senderLabel = senderType === 'admin' ? 'Admin reply' : 'Visitor message';
    const senderName = safeText(message.sender_name || (senderType === 'admin' ? adminState.user?.username : summary.contact_name || 'Visitor'));
    const senderEmail = message.sender_email || summary.contact_email || '';

    return `
      <article class="message-bubble ${senderType}">
        <div class="message-bubble-header">
          <strong>${senderLabel}</strong>
          <span>${senderName}${senderEmail ? ` · ${senderEmail}` : ''}</span>
          <span>${formatTime(message.created_at)}</span>
        </div>
        <p>${escapeHtml(message.body || '')}</p>
      </article>
    `;
  };

  const visitorEmail = summary.contact_email ? ` · ${summary.contact_email}` : '';
  const status = summary.status || 'open';

  conversationPanel.innerHTML = `
    <div class="conversation-shell">
      <div class="conversation-summary">
        <div class="conversation-summary-header">
          <div class="conversation-status-badge">${escapeHtml(status)}</div>
        </div>
        <div class="conversation-summary-grid">
          <div><strong>Visitor</strong><br>${escapeHtml(summary.contact_name || 'Unknown visitor')}${escapeHtml(visitorEmail)}</div>
          <div><strong>Subject</strong><br>${escapeHtml(summary.subject || 'No subject')}</div>
          <div><strong>Created</strong><br>${escapeHtml(formatTime(summary.created_at))}</div>
          <div><strong>Last update</strong><br>${escapeHtml(formatTime(summary.last_message_at || summary.updated_at))}</div>
        </div>
      </div>

      <div class="conversation-thread">
        ${messageItems.map(renderMessage).join('') || '<p class="inline-status">No messages available yet.</p>'}
      </div>

      <div class="reply-box">
        <label>
          <span>Reply</span>
          <textarea id="adminReplyInput" placeholder="Write a response to the visitor..."></textarea>
        </label>
        <div class="reply-actions">
          <button type="button" id="sendReplyBtn" class="button button-primary">Reply</button>
          <p id="replyStatus" class="inline-status" aria-live="polite"></p>
        </div>
      </div>
    </div>
  `;

  const replyInput = document.getElementById('adminReplyInput');
  const sendReplyBtn = document.getElementById('sendReplyBtn');
  const replyStatus = document.getElementById('replyStatus');

  sendReplyBtn.addEventListener('click', async () => {
    const body = safeText(replyInput.value);
    if (!body) {
      setStatus(replyStatus, 'Please enter a reply before sending.', 'error');
      replyInput.focus();
      return;
    }
    if (body.length > 65535) {
      setStatus(replyStatus, 'Reply is too long. Please shorten it and try again.', 'error');
      return;
    }

    sendReplyBtn.disabled = true;
    setStatus(replyStatus, 'Sending reply...', '');

    try {
      const result = await apiRequest(`${API_BASE_URL}/api/conversation/reply?id=${summary.id}`, {
        method: 'POST',
        body: JSON.stringify({ body }),
        headers: { Authorization: `Bearer ${adminState.token}` },
      });

      if (!result || !result.ok) {
        throw new Error(result && result.error ? result.error : 'Reply failed.');
      }

      replyInput.value = '';
      const refreshed = await loadConversation(summary.id, true);
      if (!refreshed) {
        throw new Error('Reply was sent, but the conversation could not be refreshed.');
      }
      setStatus(document.getElementById('replyStatus'), 'Reply sent successfully.', 'success');
    } catch (error) {
      console.error('Reply failed:', error);
      setStatus(replyStatus, mapApiError(error, 'The reply could not be sent.'), 'error');
    } finally {
      sendReplyBtn.disabled = false;
    }
  });
};

const escapeHtml = (value = '') => value
  .replace(/&/g, '&amp;')
  .replace(/</g, '&lt;')
  .replace(/>/g, '&gt;')
  .replace(/"/g, '&quot;')
  .replace(/'/g, '&#039;');

const openNotificationDropdown = () => {
  notificationDropdown.classList.toggle('is-open');
  notificationBell.setAttribute('aria-expanded', String(notificationDropdown.classList.contains('is-open')));
};

const fetchNotifications = async () => {
  if (!adminState.token) {
    return;
  }

  try {
    const result = await apiRequest(`${API_BASE_URL}/api/notifications?page=1&per_page=20`, {
      headers: {
        Authorization: `Bearer ${adminState.token}`,
      },
    });

    if (!result || !result.ok) {
      throw new Error(result && result.error ? result.error : 'Unable to load notifications.');
    }

    adminState.notifications = Array.isArray(result.data) ? result.data : [];
    renderNotificationCount();

    if (!notificationList) {
      return;
    }

    notificationList.innerHTML = adminState.notifications.length === 0
      ? '<li><div class="notification-item-meta">No notifications yet.</div></li>'
      : adminState.notifications.map((item) => `
        <li>
          <button type="button" class="notification-item-button" data-conversation-id="${escapeHtml(item.conversation_id || '')}">
            <span class="notification-item-title">${escapeHtml(item.contact_name || 'Visitor enquiry')}</span>
            <span class="notification-item-meta">${escapeHtml(item.notification_type || 'new_contact_message')} · ${escapeHtml(formatTime(item.created_at || item.sent_at))}</span>
            <span class="notification-item-meta">${escapeHtml(item.subject || 'No subject')}</span>
          </button>
        </li>
      `).join('');

    notificationList.querySelectorAll('[data-conversation-id]').forEach((button) => {
      button.addEventListener('click', async () => {
        const conversationId = button.getAttribute('data-conversation-id');
        if (!conversationId) {
          return;
        }
        await loadConversation(conversationId, true);
        notificationDropdown.classList.remove('is-open');
        notificationBell.setAttribute('aria-expanded', 'false');
      });
    });
  } catch (error) {
    console.error('Failed to fetch notifications:', error);
    setStatus(adminLoginStatus, mapApiError(error, 'Unable to load notifications.'), 'error');
  }
};

const loadConversation = async (conversationId, shouldRefreshNotifications = false) => {
  if (!conversationId) {
    return;
  }

  try {
    const result = await apiRequest(`${API_BASE_URL}/api/conversation?id=${encodeURIComponent(conversationId)}`, {
      headers: {
        Authorization: `Bearer ${adminState.token}`,
      },
    });

    if (!result || !result.ok) {
      throw new Error(result && result.error ? result.error : 'Conversation unavailable.');
    }

    adminState.selectedConversationId = conversationId;
    adminState.conversation = result;
    renderConversation();
    if (shouldRefreshNotifications) {
      await fetchNotifications();
    }
    return true;
  } catch (error) {
    console.error('Conversation load failed:', error);
    setStatus(adminLoginStatus, mapApiError(error, 'The selected conversation is unavailable.'), 'error');
    return false;
  }
};

const initializeAdmin = () => {
  const savedSession = readStoredSession();
  if (savedSession && savedSession.token && savedSession.user) {
    adminState.token = savedSession.token;
    adminState.user = savedSession.user;
    renderAdminState();
    fetchNotifications();
  }
};

if (adminToggle) {
  adminToggle.addEventListener('click', () => {
    const isAuthenticated = Boolean(adminState.token && adminState.user);
    if (!isAuthenticated) {
      adminPanel.hidden = false;
      adminLoginSection.hidden = false;
      adminDashboardSection.hidden = true;
      adminLoginForm?.querySelector('input')?.focus();
      return;
    }
    adminPanel.hidden = false;
    adminDashboardSection.hidden = false;
    adminLoginSection.hidden = true;
    notificationDropdown.classList.toggle('is-open');
    notificationBell.setAttribute('aria-expanded', String(notificationDropdown.classList.contains('is-open')));
    fetchNotifications();
  });
}

if (notificationBell) {
  notificationBell.addEventListener('click', () => {
    if (!adminState.token) {
      return;
    }
    openNotificationDropdown();
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
      setStatus(adminLoginStatus, 'Please provide both email and password.', 'error');
      return;
    }

    const submitButton = adminLoginForm.querySelector('button[type="submit"]');
    submitButton.disabled = true;
    setStatus(adminLoginStatus, 'Signing in...', '');

    try {
      const result = await apiRequest(`${API_BASE_URL}/api/auth/login`, {
        method: 'POST',
        body: JSON.stringify({ email, password }),
      });

      if (!result || !result.ok || !result.token) {
        throw new Error(result && result.error ? result.error : 'Login failed.');
      }

      adminState.token = result.token;
      adminState.user = result.admin || null;
      persistAdminSession();
      renderAdminState();
      await fetchNotifications();
      setStatus(adminLoginStatus, 'Signed in successfully.', 'success');
    } catch (error) {
      console.error('Admin login failed:', error);
      setStatus(adminLoginStatus, mapApiError(error, 'Unable to sign in. Please try again.'), 'error');
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

initializeAdmin();
renderAdminState();
renderConversation();
renderNotificationCount();
