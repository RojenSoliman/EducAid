/**
 * Notification Management Module
 */
export class NotificationManager {
    show(message, type = 'error') {
        const notifier = document.getElementById('notifier');
        if (!notifier) return;

        notifier.textContent = message;
        notifier.classList.remove('success', 'error');
        notifier.classList.add(type);
        notifier.style.display = 'block';

        setTimeout(() => {
            notifier.style.display = 'none';
        }, 3000);
    }

    showSuccess(message) {
        this.show(message, 'success');
    }

    showError(message) {
        this.show(message, 'error');
    }
}