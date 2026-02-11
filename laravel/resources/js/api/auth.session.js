import { fetchJSON } from './http.js';

function csrfToken() {
  const meta = document.querySelector('meta[name="csrf-token"]');
  return meta?.content ?? '';
}

export function login(email, password, remember = true) {
  return fetchJSON('/api/login', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfToken(),
    },
    body: JSON.stringify({ email, password, remember }),
  });
}

export function me() {
  return fetchJSON('/api/me');
}

export function logout() {
  return fetchJSON('/api/logout', {
    method: 'POST',
    headers: { 'X-CSRF-TOKEN': csrfToken() },
  });
}
