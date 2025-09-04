export function api(path) {
  const id = window.familyAppId || 'familybudget'
  return `/apps/${id}${path}`
}

export function ocs(path) {
  return `/ocs/v2.php${path}`
}

