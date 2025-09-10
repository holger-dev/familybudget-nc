export function api(path) {
  const id = window.familyAppId || 'familybudget'
  return `/apps/${id}${path}`
}

function readRequestTokenOnce() {
  try {
    if (window?.OC?.requestToken) return window.OC.requestToken
    const head = document?.head
    const viaHead = head?.getAttribute?.('data-requesttoken')
    if (viaHead) return viaHead
    const meta = document?.querySelector?.('meta[name="requesttoken"]')
    const viaMeta = meta?.getAttribute?.('content')
    if (viaMeta) return viaMeta
  } catch (_) {}
  return null
}

async function ensureRequestToken(timeoutMs = 1000) {
  const start = Date.now()
  let token = readRequestTokenOnce()
  while (!token && (Date.now() - start) < timeoutMs) {
    await new Promise(r => setTimeout(r, 50))
    token = readRequestTokenOnce()
  }
  return token
}

export async function apiFetch(path, options = {}) {
  let url = api(path)
  const headers = new Headers(options.headers || {})
  // Add Nextcloud request token for CSRF protection
  const method = ((options.method || 'GET') + '').toUpperCase()
  const needsToken = method !== 'GET' && method !== 'HEAD'
  let token = readRequestTokenOnce()
  if (!token && needsToken) {
    token = await ensureRequestToken(1500)
  }
  if (token) headers.set('requesttoken', token)
  // If JSON body, also include token inside payload for servers expecting it there
  if (token && options.body && !(options.body instanceof FormData)) {
    if (typeof options.body === 'object') {
      options = { ...options, body: { ...options.body, requesttoken: token } }
    }
  }
  // Auto set JSON content-type if body is plain object and no content-type set
  if (options.body && !(options.body instanceof FormData)) {
    if (!headers.has('Content-Type')) headers.set('Content-Type', 'application/json')
    if (typeof options.body !== 'string') {
      options = { ...options, body: JSON.stringify(options.body) }
    }
  }
  const fetchOpts = { ...options, headers }
  if (!('credentials' in fetchOpts)) fetchOpts.credentials = 'same-origin'
  if (!headers.has('Accept')) headers.set('Accept', 'application/json')
  if (!headers.has('X-Requested-With')) headers.set('X-Requested-With', 'XMLHttpRequest')

  // Extra fallback: add token as query param for non-GET/HEAD
  const method2 = (fetchOpts.method || 'GET').toUpperCase()
  if (method2 !== 'GET' && method2 !== 'HEAD') {
    const token = headers.get('requesttoken')
    if (token) {
      const join = url.includes('?') ? '&' : '?'
      url = `${url}${join}requesttoken=${encodeURIComponent(token)}`
    }
  }
  return fetch(url, fetchOpts)
}

export function ocs(path) {
  return `/ocs/v2.php${path}`
}

export async function ocsFetch(path, options = {}) {
  const headers = new Headers(options.headers || {})
  headers.set('OCS-APIRequest', 'true')
  if (!headers.has('Accept')) headers.set('Accept', 'application/json')
  const fetchOpts = { ...options, headers }
  if (!('credentials' in fetchOpts)) fetchOpts.credentials = 'same-origin'
  let url = ocs(path)
  // Prefer JSON responses from OCS unless requesting CSV
  const accept = headers.get('Accept') || ''
  const wantsCsv = accept.includes('text/csv') || /\.csv(\?|$)/.test(path)
  if (!wantsCsv) {
    url += (url.includes('?') ? '&' : '?') + 'format=json'
  }
  return fetch(url, fetchOpts)
}
