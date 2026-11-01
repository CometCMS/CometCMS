// Fetches the current weather from Open-Meteo. The manifest's permissions allow
// exactly https://api.open-meteo.com in the page's connect-src, the "units" and
// "refresh_minutes" settings arrive in ctx.settings, numbers are formatted in the
// page language (ctx.locale), and requests stop when ctx.signal aborts.

// WMO weather interpretation codes, grouped as Open-Meteo documents them.
const CONDITIONS = [
  [[0], '☀️'], [[1, 2], '🌤️'], [[3], '☁️'], [[45, 48], '🌫️'],
  [[51, 53, 55, 56, 57, 61, 63, 65, 66, 67, 80, 81, 82], '🌧️'],
  [[71, 73, 75, 77, 85, 86], '🌨️'], [[95, 96, 99], '⛈️'],
];

export function parseLocation(value) {
  const match = String(value ?? '').trim().match(/^(-?\d{1,2}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)$/);
  if (!match) return null;
  const [latitude, longitude] = [Number(match[1]), Number(match[2])];
  return Math.abs(latitude) <= 90 && Math.abs(longitude) <= 180 ? { latitude, longitude } : null;
}

export function forecastUrl(location, units) {
  const query = new URLSearchParams({
    latitude: String(location.latitude),
    longitude: String(location.longitude),
    current: 'temperature_2m,weather_code,wind_speed_10m',
    ...(units === 'imperial' ? { temperature_unit: 'fahrenheit', wind_speed_unit: 'mph' } : {}),
  });
  return `https://api.open-meteo.com/v1/forecast?${query}`;
}

export default function mount(element, props, ctx = {}) {
  const root = element.querySelector('.cometcms-weather');
  const now = root.querySelector('[data-now]');
  const wind = root.querySelector('[data-wind]');
  const windValue = root.querySelector('[data-wind-value]');
  const settings = ctx.settings ?? {};
  const imperial = settings.units === 'imperial';
  const location = parseLocation(props.location);
  const format = new Intl.NumberFormat(ctx.locale || undefined, { maximumFractionDigits: 0 });
  let timer;

  async function update() {
    try {
      const response = await fetch(forecastUrl(location, settings.units), { signal: ctx.signal });
      if (!response.ok) throw new Error(`Open-Meteo answered ${response.status}`);
      const { current } = await response.json();
      const icon = CONDITIONS.find(([codes]) => codes.includes(current.weather_code))?.[1] ?? '';
      now.textContent = `${icon} ${format.format(current.temperature_2m)} ${imperial ? '°F' : '°C'}`.trim();
      if (wind) {
        windValue.textContent = `${format.format(current.wind_speed_10m)} ${imperial ? 'mph' : 'km/h'}`;
        wind.hidden = false;
      }
      root.dataset.state = 'ready';
    } catch (error) {
      if (error.name === 'AbortError') return;
      now.textContent = props.error_label;
      root.dataset.state = 'error';
    }
  }

  if (!location) {
    now.textContent = props.error_label;
    root.dataset.state = 'error';
    return () => {};
  }
  update();
  // Previews load once; published pages keep the reading fresh.
  const minutes = Number(settings.refresh_minutes) || 0;
  if (minutes > 0 && !ctx.preview) timer = setInterval(update, minutes * 60000);
  element.dataset.mounted = 'true';
  return () => clearInterval(timer);
}
