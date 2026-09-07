document.documentElement.classList.add('js');

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

const csrfTokenField = document.getElementById('csrfToken');
let csrfRequest = null;

if (csrfTokenField && window.location.protocol !== 'file:') {
  csrfRequest = fetch('assets/php/csrf.php', { credentials: 'same-origin' })
    .then((response) => response.ok ? response.json() : null)
    .then((result) => {
      if (result && result.success && result.token) {
        csrfTokenField.value = result.token;
      }
    })
    .catch(() => {
      csrfTokenField.value = '';
    });
}

const openEmailFallback = (formData) => {
  const name = String(formData.get('name') || '').trim();
  const email = String(formData.get('email') || '').trim();
  const message = String(formData.get('message') || '').trim();
  const subject = encodeURIComponent(`Portfolio enquiry from ${name || 'a visitor'}`);
  const body = encodeURIComponent(`Name: ${name}\nEmail: ${email}\n\n${message}`);

  window.location.href = `mailto:yangdarenaud893@gmail.com?subject=${subject}&body=${body}`;
};

if (form) {
  form.addEventListener('submit', async (event) => {
    event.preventDefault();

    const formData = new FormData(form);
    const submitButton = form.querySelector('button[type="submit"]');

    submitButton.disabled = true;
    submitButton.textContent = 'Sending...';

    try {
      if (csrfRequest && !csrfTokenField.value) {
        await csrfRequest;
      }

      if (window.location.protocol === 'file:') {
        openEmailFallback(formData);
        statusBox.classList.remove('error');
        statusBox.textContent = 'Opening your email client...';
        return;
      }

      const response = await fetch('assets/php/contact.php', {
        method: 'POST',
        body: formData
      });

      const contentType = response.headers.get('content-type') || '';
      if (!contentType.includes('application/json')) {
        openEmailFallback(formData);
        statusBox.classList.remove('error');
        statusBox.textContent = 'PHP is unavailable, so your email client will finish the message.';
        return;
      }

      const result = await response.json();

      if (!response.ok || !result.success) {
        throw new Error(result.message || 'Submission failed.');
      }

      statusBox.classList.remove('error');
      statusBox.textContent = result.message;
      form.reset();
    } catch (error) {
      if (error instanceof TypeError) {
        openEmailFallback(formData);
        statusBox.classList.remove('error');
        statusBox.textContent = 'The server is unavailable, so your email client will finish the message.';
        return;
      }

      statusBox.classList.add('error');
      statusBox.textContent = error.message || 'Something went wrong. Please try again.';
    } finally {
      submitButton.disabled = false;
      submitButton.textContent = 'Send message';
    }
  });
}
