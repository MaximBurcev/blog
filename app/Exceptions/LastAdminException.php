<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Попытка отобрать роль администратора у последнего оставшегося админа.
 *
 * Инвариант держится на модели, а не только в форме: Filament-овский
 * disableOptionWhen дизейблит опцию лишь в разметке, валидационного правила
 * из него не выводится, и прямой Livewire-запрос со значением Reader
 * проходил бы мимо.
 */
class LastAdminException extends RuntimeException {}
