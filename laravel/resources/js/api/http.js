export async function fetchJSON(url, options = {}) {
  const res = await fetch(url, {
    headers: {
      'Accept': 'application/json',
      ...(options.headers ?? {}),
    },
    ...options,
  });

  const text = await res.text();
  let data = null;
  try { data = text ? JSON.parse(text) : null; } catch {}

  if (!res.ok) {
    const message = data?.message || data?.error || `HTTP ${res.status}`;
    throw new Error(message);
  }
  return data;
}
