<?php
define('ADMIN_MODULE_NAME', 'prospektweb.calc');
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Prospektweb\LayoutFiles\BackupManager;
use Prospektweb\LayoutFiles\Config;
use Prospektweb\LayoutFiles\Logger;
use Prospektweb\LayoutFiles\Service\YandexDiskClient;

require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_admin_before.php';
Loader::includeModule('prospektweb.calc');
$moduleId = Config::MODULE_ID;
$messages = [];

$saveFileSettings = static function () use ($moduleId): void {
    foreach (['base_folder','max_size','extensions','temp_lifetime_hours','tooltip_text','desired_receive_tooltip_text','desired_receive_min_hours','desired_receive_workdays','desired_receive_time_from','desired_receive_time_to','desired_receive_step_minutes','desired_receive_default_time','desired_receive_holidays','desired_receive_production_hours_property','hidden_basket_property_codes'] as $name) {
        $postedValue = $_POST[$name] ?? '';
        $value = is_array($postedValue) ? implode(',', array_map('trim', $postedValue)) : trim((string)$postedValue);
        if ($name === 'desired_receive_holidays') {
            $dates = [];
            foreach (array_map('trim', explode(',', $value)) as $date) {
                if (preg_match('/^\d{2}\.\d{2}$/', $date)) {
                    $dates[] = $date;
                } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    $dates[] = substr($date, 8, 2) . '.' . substr($date, 5, 2);
                }
            }
            $value = implode(',', array_values(array_unique($dates)));
        }
        Option::set($moduleId, $name, $value);
    }
};
$saveOAuthSettings = static function () use ($moduleId): void {
    Option::set($moduleId, 'yadisk_client_id', trim((string)($_POST['yadisk_client_id'] ?? '')));
    $secret = trim((string)($_POST['yadisk_client_secret'] ?? ''));
    if ($secret !== '') {
        Option::set($moduleId, 'yadisk_client_secret', $secret);
    }
};
$ensureConnected = static function (): void {
    if (Config::getToken() === '' && Config::getRefreshToken() === '') {
        throw new RuntimeException('Сначала подключите Яндекс.Диск во вкладке “Яндекс.Диск”.');
    }
};
$exchangeCode = static function (string $code): array {
    $client = new HttpClient(['socketTimeout' => 20, 'streamTimeout' => 60]);
    $client->setHeader('Content-Type', 'application/x-www-form-urlencoded');
    $body = $client->post('https://oauth.yandex.ru/token', http_build_query([
        'grant_type' => 'authorization_code',
        'code' => $code,
        'client_id' => Config::getClientId(),
        'client_secret' => Config::getClientSecret(),
    ]));
    $status = (int)$client->getStatus();
    $data = $body !== '' ? Json::decode($body) : [];
    if ($status < 200 || $status >= 300) {
        $error = (string)($data['error'] ?? '');
        if ($error === 'invalid_grant') {
            throw new RuntimeException('Код подтверждения устарел или уже использован. Получите новый код и повторите подключение.');
        }
        throw new RuntimeException('Яндекс OAuth вернул ошибку: HTTP ' . $status . ' ' . $body);
    }
    if (empty($data['access_token'])) {
        throw new RuntimeException('Яндекс OAuth не вернул access_token.');
    }
    return $data;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    $action = (string)($_POST['action'] ?? 'save_files');

    if ($action === 'save_files') {
        $saveFileSettings();
        $messages[] = ['TYPE' => 'OK', 'MESSAGE' => 'Параметры загрузки файлов сохранены.'];
    } elseif ($action === 'finish_oauth') {
        try {
            $saveOAuthSettings();
            $saveFileSettings();
            if (Config::getClientId() === '') { throw new RuntimeException('Укажите Client ID OAuth-приложения Яндекса.'); }
            if (Config::getClientSecret() === '') { throw new RuntimeException('Укажите Client Secret OAuth-приложения Яндекса.'); }
            $code = trim((string)($_POST['oauth_code'] ?? ''));
            if ($code === '') {
                $messages[] = ['TYPE' => 'OK', 'MESSAGE' => 'Настройки Яндекс.Диска сохранены.'];
            } else {
                $data = $exchangeCode($code);
                Config::setToken((string)$data['access_token']);
                if (!empty($data['refresh_token'])) { Config::setRefreshToken((string)$data['refresh_token']); }
                Config::setConnectedAt(date('c'));
                try {
                    $info = (new YandexDiskClient())->getDiskInfo();
                    $account = (string)($info['user']['login'] ?? $info['user']['display_name'] ?? '');
                    if ($account !== '') { Config::setConnectedAccount($account); }
                } catch (Throwable $ignored) {}
                $messages[] = ['TYPE' => 'OK', 'MESSAGE' => 'Яндекс.Диск подключён.'];
            }
        } catch (Throwable $e) {
            Logger::error('oauth.finish', $e);
            $messages[] = ['TYPE' => 'ERROR', 'MESSAGE' => $e->getMessage()];
        }
    } elseif ($action === 'cleanup_legacy_patches') {
        $cleanupReport = BackupManager::cleanupLegacyPatches();
        $cleaned = array_keys(array_filter($cleanupReport, static function ($status): bool { return $status === 'cleaned'; }));
        $errors = array_filter($cleanupReport, static function ($status): bool { return strpos((string)$status, 'error:') === 0; });
        if ($errors) {
            $messages[] = ['TYPE' => 'ERROR', 'MESSAGE' => 'Legacy-патчи очищены с ошибками: ' . implode('; ', array_map(static function ($path, $status): string { return $path . ' — ' . $status; }, array_keys($errors), $errors))];
        } elseif ($cleaned) {
            $messages[] = ['TYPE' => 'OK', 'MESSAGE' => 'Legacy-патчи корзины очищены: ' . implode(', ', $cleaned)];
        } else {
            $messages[] = ['TYPE' => 'OK', 'MESSAGE' => 'Legacy-патчи корзины не найдены или уже очищены.'];
        }
    } elseif ($action === 'disconnect') {
        Config::clearTokens();
        $messages[] = ['TYPE' => 'OK', 'MESSAGE' => 'Яндекс.Диск отключён.'];
    } elseif ($action === 'check_connection') {
        try {
            $ensureConnected();
            $info = (new YandexDiskClient())->checkConnection();
            $account = (string)($info['user']['login'] ?? $info['user']['display_name'] ?? '');
            if ($account !== '') { Config::setConnectedAccount($account); }
            $messages[] = ['TYPE' => 'OK', 'MESSAGE' => 'Подключение к Яндекс.Диску работает.'];
        } catch (Throwable $e) {
            $messages[] = ['TYPE' => 'ERROR', 'MESSAGE' => $e->getMessage()];
        }
    }
}

$token = Config::getToken();
$refreshToken = Config::getRefreshToken();
$connected = $token !== '' || $refreshToken !== '';

$propertyOptions = [];
$numberPropertyOptions = [];
if (Loader::includeModule('iblock') && class_exists('CIBlockProperty')) {
    $propertyIterator = CIBlockProperty::GetList(['IBLOCK_ID' => 'ASC', 'SORT' => 'ASC', 'NAME' => 'ASC'], ['ACTIVE' => 'Y']);
    while ($property = $propertyIterator->Fetch()) {
        $code = trim((string)($property['CODE'] ?? ''));
        if ($code === '') {
            continue;
        }
        $label = '[' . $code . '] ' . (string)($property['NAME'] ?? $code) . ' (IBLOCK ' . (int)($property['IBLOCK_ID'] ?? 0) . ')';
        $propertyOptions[$code] = $label;
        if (($property['PROPERTY_TYPE'] ?? '') === 'N') {
            $numberPropertyOptions[$code] = $label;
        }
    }
}
asort($propertyOptions);
asort($numberPropertyOptions);
$selectedHiddenPropertyCodes = Config::getHiddenBasketPropertyCodes();
$aTabs = [
    ['DIV'=>'files','TAB'=>'Загрузка файлов','TITLE'=>'Параметры загрузки файлов'],
    ['DIV'=>'yadisk','TAB'=>'Яндекс.Диск','TITLE'=>'Подключение Яндекс.Диска'],
    ['DIV'=>'service','TAB'=>'Сервис','TITLE'=>'Сервисные сведения'],
];
$tabControl = new CAdminTabControl('tabControl', $aTabs);
require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_admin_after.php';
?>
<style>.prospekt-layoutfiles-actions .adm-btn,.prospekt-layoutfiles-actions .adm-btn-save{margin-right:8px;cursor:pointer;}</style>
<?php
foreach ($messages as $message) {
    CAdminMessage::ShowMessage(['TYPE' => $message['TYPE'], 'MESSAGE' => $message['MESSAGE']]);
}
?>
<form method="post">
<?=bitrix_sessid_post()?>
<?$tabControl->Begin();?>
<?$tabControl->BeginNextTab();?>
<tr><td width="40%">Максимальный размер файла, байт</td><td><input type="text" name="max_size" value="<?=htmlspecialcharsbx(Option::get($moduleId,'max_size',Config::DEFAULT_MAX_SIZE))?>"></td></tr>
<tr><td>Разрешённые расширения</td><td><input type="text" name="extensions" size="60" value="<?=htmlspecialcharsbx(Option::get($moduleId,'extensions',Config::DEFAULT_EXTENSIONS))?>"></td></tr>
<tr><td>Удалять временные файлы через, часов</td><td><input type="text" name="temp_lifetime_hours" value="<?=htmlspecialcharsbx(Option::get($moduleId,'temp_lifetime_hours',24))?>"></td></tr>
<tr><td>Текст подсказки кнопки макета</td><td><textarea name="tooltip_text" cols="80" rows="3"><?=htmlspecialcharsbx(Option::get($moduleId,'tooltip_text',Config::DEFAULT_TOOLTIP_TEXT))?></textarea></td></tr>
<tr class="heading"><td colspan="2">Желаемая дата получения</td></tr>
<tr><td>Текст подсказки поля &quot;Желаемая дата получения&quot;</td><td><textarea name="desired_receive_tooltip_text" cols="80" rows="3"><?=htmlspecialcharsbx(Option::get($moduleId,'desired_receive_tooltip_text',Config::DEFAULT_DESIRED_RECEIVE_TOOLTIP_TEXT))?></textarea></td></tr>
<tr><td>Минимальный задел, часов</td><td><input type="text" name="desired_receive_min_hours" value="<?=htmlspecialcharsbx(Option::get($moduleId,'desired_receive_min_hours',4))?>"></td></tr>
<tr><td>Свойство планового срока готовности</td><td><select name="desired_receive_production_hours_property"><option value="">По умолчанию: <?=htmlspecialcharsbx(Config::DEFAULT_PRODUCTION_HOURS_PROPERTY_CODE)?></option><?foreach ($numberPropertyOptions as $code => $label) {?><option value="<?=htmlspecialcharsbx($code)?>"<?=Config::getDesiredReceiveProductionHoursPropertyCode() === $code ? ' selected' : ''?>><?=htmlspecialcharsbx($label)?></option><?}?></select><br><small>Используется числовое свойство товара/торгового предложения, значение — рабочие часы.</small></td></tr>
<tr><td>Рабочие дни недели</td><td><input type="text" name="desired_receive_workdays" size="30" value="<?=htmlspecialcharsbx(Option::get($moduleId,'desired_receive_workdays','1,2,3,4,5'))?>"> <small>1 — понедельник, 7 — воскресенье</small></td></tr>
<tr><td>Рабочее время с</td><td><input type="text" name="desired_receive_time_from" value="<?=htmlspecialcharsbx(Option::get($moduleId,'desired_receive_time_from','09:00'))?>"></td></tr>
<tr><td>Рабочее время до</td><td><input type="text" name="desired_receive_time_to" value="<?=htmlspecialcharsbx(Option::get($moduleId,'desired_receive_time_to','18:00'))?>"></td></tr>
<tr><td>Шаг выбора, минут</td><td><input type="text" name="desired_receive_step_minutes" value="<?=htmlspecialcharsbx(Option::get($moduleId,'desired_receive_step_minutes',30))?>"></td></tr>
<tr><td>Значение по умолчанию для желаемого времени</td><td><input type="text" name="desired_receive_default_time" value="<?=htmlspecialcharsbx(Option::get($moduleId,'desired_receive_default_time','11:00'))?>"> <small>Формат: HH:MM</small></td></tr>
<tr><td>Выходные даты</td><td><input type="text" name="desired_receive_holidays" size="60" value="<?=htmlspecialcharsbx(implode(',', Config::getDesiredReceiveHolidays()))?>"> <small>Формат: DD.MM, через запятую. Даты применяются для любого года</small></td></tr>
<tr class="heading"><td colspan="2">Скрытие свойств товарной позиции в корзине</td></tr>
<tr><td>Скрываемые свойства</td><td><select name="hidden_basket_property_codes[]" multiple size="10" style="min-width:520px;"><?foreach ($propertyOptions as $code => $label) {?><option value="<?=htmlspecialcharsbx($code)?>"<?=in_array($code, $selectedHiddenPropertyCodes, true) ? ' selected' : ''?>><?=htmlspecialcharsbx($label)?></option><?}?></select><br><small>Выбранные свойства будут скрыты в корзине по атрибуту data-property-code. Служебные свойства модуля скрываются всегда.</small></td></tr>
<tr><td></td><td class="prospekt-layoutfiles-actions"><button type="submit" class="adm-btn-save" name="action" value="save_files">Сохранить</button></td></tr>

<?$tabControl->BeginNextTab();?>
<tr class="heading"><td colspan="2">OAuth-приложение</td></tr>
<tr><td width="40%">Client ID</td><td><input type="text" name="yadisk_client_id" size="60" value="<?=htmlspecialcharsbx(Option::get($moduleId,'yadisk_client_id',''))?>"></td></tr>
<tr><td>Client Secret</td><td><input type="password" name="yadisk_client_secret" size="60" value="" placeholder="Оставьте пустым, чтобы не менять"></td></tr>
<tr><td></td><td class="prospekt-layoutfiles-actions"><button class="adm-btn" type="submit" formaction="/local/tools/prospekt_layout/oauth_start.php" formtarget="_blank">Подключить</button></td></tr>
<tr><td></td><td>Данные OAuth-приложения Яндекса. Используются только на сервере.</td></tr>
<tr class="heading"><td colspan="2">Подключение</td></tr>
<tr><td>Статус</td><td><b><?=$connected ? 'Подключено' : 'Не подключено'?></b></td></tr>
<tr><td>Аккаунт Яндекса</td><td><?=htmlspecialcharsbx(Config::getConnectedAccount() ?: '—')?></td></tr>
<tr><td>Дата подключения</td><td><?=htmlspecialcharsbx(Config::getConnectedAt() ?: '—')?></td></tr>
<tr><td>Токен доступа</td><td><?=$token !== '' ? 'сохранён' : 'отсутствует'?></td></tr>
<tr><td>Refresh token</td><td><?=$refreshToken !== '' ? 'сохранён' : 'отсутствует'?></td></tr>
<tr><td>Код подтверждения</td><td><input type="text" name="oauth_code" size="40" value=""><br><small>После разрешения доступа Яндекс покажет код. Скопируйте его и вставьте в это поле.</small></td></tr>
<tr><td></td><td class="prospekt-layoutfiles-actions"><button class="adm-btn-save" type="submit" name="action" value="finish_oauth">Сохранить</button><button class="adm-btn" type="submit" name="action" value="check_connection">Проверить подключение</button><button class="adm-btn" type="submit" name="action" value="disconnect" onclick="return confirm('Отключить Яндекс.Диск?')">Отключить</button></td></tr>
<tr><td>Базовая папка на Яндекс.Диске</td><td><input type="text" name="base_folder" size="60" value="<?=htmlspecialcharsbx(Option::get($moduleId,'base_folder','/'))?>"><br><small>Укажите путь к уже созданной папке. По умолчанию используется корень Яндекс.Диска.</small></td></tr>

<?$tabControl->BeginNextTab();?>
<tr><td width="40%">Путь к логам</td><td>Журнал событий Битрикс, модуль <code>prospektweb.calc</code></td></tr>
<tr><td>Путь к backup</td><td><code>/upload/prospekt_layoutfiles_backups/</code></td></tr>
<tr><td>Текущий install_id</td><td><code><?=htmlspecialcharsbx(Option::get($moduleId, 'backup_install_id', '—'))?></code></td></tr>
<tr><td>Инструкция</td><td><code>/local/modules/prospektweb.calc/docs/OPERATIONS.md</code> или <code>/bitrix/modules/prospektweb.calc/docs/OPERATIONS.md</code></td></tr>
<tr class="heading"><td colspan="2">Обслуживание шаблона корзины</td></tr>
<tr><td>Legacy-патчи корзины</td><td class="prospekt-layoutfiles-actions"><button class="adm-btn" type="submit" name="action" value="cleanup_legacy_patches" onclick="return confirm('Очистить legacy-патчи корзины?')">Очистить legacy-патчи корзины</button><br><small>Удаляет известные устаревшие фрагменты модуля из шаблонов корзины без полного rollback файла.</small></td></tr>
<?$tabControl->End();?>
</form>
<?require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/epilog_admin.php';?>
