const documents = new WeakSet();

// Elements whose scripts set them up once on load; a list that brings new ones opens as a new page.
const SET_UP_ON_LOAD = '[data-tabs], [data-search], [data-background-video], [data-form-captcha]';

/**
 * Page and filter entry lists in place: the next state of the page is
 * fetched and its lists replace the current ones, so the visitor stays where
 * they are instead of starting again at the top. Without this script, page
 * links and the filter form still work as plain links and a form.
 */
export function initListFilters(doc) {
  for (const form of doc.querySelectorAll('[data-list-filters]')) form.setAttribute('data-list-filters-ready', '');
  if (documents.has(doc)) return;
  documents.add(doc);
  const win = doc.defaultView;
  let pending = null;

  doc.addEventListener('change', (event) => {
    const form = event.target.closest?.('[data-list-filters]');
    if (form && event.target.name) form.requestSubmit();
  });

  doc.addEventListener('submit', (event) => {
    const form = event.target.closest?.('[data-list-filters]');
    if (!form || event.defaultPrevented) return;
    event.preventDefault();
    // Unused fields stay out of the address, which keeps shared links short.
    const url = new URL(form.action, doc.baseURI);
    url.search = new URLSearchParams([...new FormData(form)].filter(([, value]) => value !== '')).toString();
    open(url.href, form.closest('[data-list]'), false);
  });

  doc.addEventListener('click', (event) => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const link = event.target.closest?.('[data-list] .c-pagination a[href], [data-list] a.c-filter-reset[href]');
    if (!link) return;
    event.preventDefault();
    open(link.href, link.closest('[data-list]'), !link.classList.contains('c-filter-reset'));
  });

  win.addEventListener('popstate', (event) => {
    if (event.state?.cometLists) swap(win.location.href, null, false).catch(() => win.location.reload());
  });

  function open(url, list, paged) {
    swap(url, list, paged).then(
      () => {
        if (!win.history.state?.cometLists) win.history.replaceState({ ...win.history.state, cometLists: true }, '');
        win.history.pushState({ cometLists: true }, '', url);
      },
      (error) => { if (error?.name !== 'AbortError') win.location.assign(url); },
    );
  }

  /** Replace the page's lists with those of $url; $list is the one the visitor used. */
  async function swap(url, list, paged) {
    pending?.abort();
    const controller = pending = new AbortController();
    const current = [...doc.querySelectorAll('[data-list]')];
    for (const element of current) element.setAttribute('aria-busy', 'true');
    try {
      const response = await fetch(url, { signal: controller.signal, credentials: 'same-origin' });
      if (!response.ok || !(response.headers.get('content-type') ?? '').includes('text/html')) throw new Error('Unexpected response');
      const next = new DOMParser().parseFromString(await response.text(), 'text/html');
      const lists = [...next.querySelectorAll('[data-list]')];
      const scriptKey = (script) => script.getAttribute('src') ?? script.textContent;
      const scripts = new Set([...doc.scripts].map(scriptKey));
      // The page changed in other ways, or the new lists need a script this page lacks.
      if (lists.length !== current.length
        || lists.some((element, index) => element.dataset.list !== current[index].dataset.list || element.querySelector(SET_UP_ON_LOAD))
        || [...next.scripts].some((script) => scriptKey(script).trim() !== '' && !scripts.has(scriptKey(script)))) {
        throw new Error('Page changed');
      }
      if (controller.signal.aborted) throw new DOMException('Aborted', 'AbortError');

      const index = list ? current.indexOf(list) : -1;
      const focus = index >= 0 ? focusKey(list, doc.activeElement) : null;
      lists.forEach((element, i) => current[i].replaceWith(doc.importNode(element, true)));
      for (const form of doc.querySelectorAll('[data-list-filters]')) form.setAttribute('data-list-filters-ready', '');
      if (index < 0) return;

      const replaced = doc.querySelectorAll('[data-list]')[index];
      // A new page of entries starts at the list, if its start was scrolled past.
      if (paged && replaced.getBoundingClientRect().top < 0) {
        const reduce = win.matchMedia('(prefers-reduced-motion: reduce)').matches;
        replaced.scrollIntoView({ block: 'start', behavior: reduce ? 'auto' : 'smooth' });
      }
      restoreFocus(replaced, focus, paged);
    } finally {
      if (pending === controller) pending = null;
      for (const element of current) element.removeAttribute('aria-busy');
    }
  }
}

/** How to find the focused control of $list again in its replacement. */
function focusKey(list, active) {
  if (!active || !list.contains(active)) return null;
  if (active.name) return { name: active.name, value: ['checkbox', 'radio'].includes(active.type) ? active.value : null };
  return { pagination: !!active.closest('.c-pagination') };
}

function restoreFocus(list, key, paged) {
  if (!key) return;
  let target = null;
  if (key.name) {
    target = [...list.querySelectorAll('[name]')].find((field) => field.name === key.name && (key.value === null || field.value === key.value));
  } else if (paged || key.pagination) {
    // Screen readers continue at the new entries.
    list.setAttribute('tabindex', '-1');
    target = list;
  }
  target?.focus({ preventScroll: true });
}
