export function showSuccess(message) {
  try {
    if (window?.OCP?.Toast?.success) {
      window.OCP.Toast.success(message)
      return
    }
    if (window?.OC?.Notification?.showTemporary) {
      window.OC.Notification.showTemporary(message)
      return
    }
  } catch (_) {}
  console.log('SUCCESS:', message)
}

export function showError(message) {
  try {
    if (window?.OCP?.Toast?.error) {
      window.OCP.Toast.error(message)
      return
    }
    if (window?.OC?.dialogs?.alert) {
      window.OC.dialogs.alert(message)
      return
    }
  } catch (_) {}
  console.error('ERROR:', message)
}

