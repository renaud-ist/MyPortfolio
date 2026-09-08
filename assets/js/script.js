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

    statusBox.classList.remove('error');
    statusBox.textContent = 'Sending your message...';

    try {
      const response = await fetch(form.action, {
        method: 'POST',
        body: formData,
        headers: {
          Accept: 'application/json'
        }
      });

      let result = {};

      try {
        result = await response.json();
      } catch {
        result = {};
      }

      if (!response.ok) {
        const message = result.errors
          ?.map((error) => error.message)
          .join(', ');

        throw new Error(message || 'Submission failed.');
      }

      statusBox.classList.remove('error');
      statusBox.textContent =
        result.message || 'Thanks! Your message has been sent successfully.';

      form.reset();
    } catch (error) {
      console.error('Contact form submission failed:', error);

      statusBox.classList.add('error');
      statusBox.textContent =
        error.message || 'Something went wrong. Please try again.';
    } finally {
      submitButton.disabled = false;
      submitButton.textContent = 'Send message';
    }
  });
}