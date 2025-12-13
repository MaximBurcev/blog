import './bootstrap';

// Подписка на уведомления
document.addEventListener('DOMContentLoaded', () => {
    const userId = document.head.querySelector('meta[name="user-id"]')?.content;

    if (userId && window.Echo) {
        console.log('🔌 Подписываемся на канал App.Models.User.' + userId);

        // Подписка на частный канал (авторизация происходит автоматически)
        Echo.private(`App.Models.User.${userId}`);

        // Глобальный слушатель событий
        Echo.connector.pusher.bind('UserNotification', (data) => {
            console.log('🔔 Получено уведомление:', data.message);
        });
    }
});
