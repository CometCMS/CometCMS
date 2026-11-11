// Detection is a hint: keep every configured link available when there is no match.
export function detectOS(device = {}) {
  const agent = device.userAgent || '';
  const platform = device.userAgentData?.platform || device.platform || '';
  // Mobile identifiers take priority over their Linux/macOS compatibility tokens.
  if (/iPhone|iPad|iPod/i.test(agent) || (/Mac/i.test(platform) && device.maxTouchPoints > 1)) return 'ios';
  if (/Android/i.test(agent) || /Android/i.test(platform)) return 'android';
  if (/CrOS/i.test(agent) || /Chrome OS|Chromium OS|ChromeOS/i.test(platform)) return 'chromeos';
  if (/Win/i.test(platform) || /Windows/i.test(agent)) return 'windows';
  if (/Mac/i.test(platform) || /Macintosh|Mac OS X/i.test(agent)) return 'macos';
  if (/Linux/i.test(platform) || /Linux|X11/i.test(agent)) return 'linux';
  return 'other';
}

export default function mount(element, props) {
  const items = [...element.querySelectorAll('.cometcms-download__item')];
  const os = detectOS(navigator);
  const hasMatch = items.some((item) => item.dataset.os === os);
  for (const item of items) {
    const matched = item.dataset.os === os;
    item.toggleAttribute('data-matched', matched);
    item.hidden = props.display === 'matched' && hasMatch && !matched;
    const recommendation = item.querySelector('[data-recommended]');
    if (recommendation) recommendation.hidden = !matched;
  }
  return () => {
    for (const item of items) {
      item.removeAttribute('data-matched');
      item.hidden = false;
      const recommendation = item.querySelector('[data-recommended]');
      if (recommendation) recommendation.hidden = true;
    }
  };
}
