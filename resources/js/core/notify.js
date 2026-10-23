import { h } from 'vue';
import { ElMessageBox, ElNotification } from 'element-plus';
import { X } from 'lucide-vue-next';

/**
 * UI adapter for notifications and dialogs. The API client talks only to this module,
 * so the UI library can be swapped here. Styles come from resources/style/app.scss.
 *
 * Passing a `key` shows at most one notification or dialog with that key at a time:
 * five parallel 401 responses open a single "log in" dialog.
 */
const active = new Set();

// Close button of dialogs: Lucide's X with a heavier line; the round background is in theme/element.scss.
const CloseIcon = () => h(X, { size: 18, strokeWidth: 2.5 });

function once(key, open) {
    if (key && active.has(key)) {
        return null;
    }

    if (key) {
        active.add(key);
    }

    return open(() => active.delete(key));
}

function notification(type, { title, message, duration = 6000, key = null }) {
    return once(key, (release) => ElNotification({
        type,
        title,
        message,
        duration,
        onClose: release,
    }));
}

/**
 * Resolves to true when confirmed, false when cancelled or already open.
 */
function confirm({ title, message, confirmText, cancelText, type = 'warning', key = null }) {
    const dialog = once(key, (release) => ElMessageBox.confirm(message, title, {
        confirmButtonText: confirmText,
        cancelButtonText: cancelText,
        closeIcon: CloseIcon,
        type,
    }).then(() => true, () => false).finally(release));

    return dialog ?? Promise.resolve(false);
}

export default {
    success: (options) => notification('success', options),
    info: (options) => notification('info', options),
    warning: (options) => notification('warning', options),
    error: (options) => notification('error', options),
    confirm,
};
