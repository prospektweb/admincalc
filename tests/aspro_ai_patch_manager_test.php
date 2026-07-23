<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Services/AsproAiPatchManager.php';

use Prospektweb\Calc\Services\AsproAiPatchManager;

function assertPatch(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removePatchFixture(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $items = scandir($path);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $target = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($target) && !is_link($target)) {
            removePatchFixture($target);
        } else {
            @unlink($target);
        }
    }
    @rmdir($path);
}

function createPatchFixture(string $root, string $version = '1.1.1'): array
{
    $asproRoot = $root . '/bitrix/modules/aspro.ai';
    $target = $asproRoot . '/lib/services/chatgpt.php';
    $storage = $root . '/bitrix/modules/prospektweb.calc/var/aspro-ai-patch';
    foreach ([dirname($target), $asproRoot . '/install', $asproRoot . '/html', $asproRoot . '/tools'] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create fixture directory: ' . $directory);
        }
    }

    $source = <<<'PHP'
<?php
class PatchFixtureBase
{
    public function __construct() {}
}
class PatchFixtureChatGPT extends PatchFixtureBase
{
    protected $baseApiUrl = 'https://api.openai.com';
    protected $arConfig = [];
    protected $token = '';

    public function __construct()
    {
        $this->arConfig = static::getConfig();
        $this->token = $this->getToken();
        parent::__construct();
    }

    protected static function getConfig(): array { return []; }
    protected function getToken(): string { return ''; }
}
PHP;
    $source .= "\n";
    file_put_contents($target, $source);
    file_put_contents(
        $asproRoot . '/install/version.php',
        "<?php\n\$arModuleVersion = ['VERSION' => '" . addslashes($version) . "'];\n"
    );
    $popupSource = <<<'PHP'
<?php
?><select name="aiType">
    <option value="GigaChat">Сбер GigaChat</option>
    <option value="ChatGPT">OpenAI ChatGPT</option>
    <option value="DeepSeek">DeepSeek</option>
</select>
PHP;
    $popupSource .= "\n";
    foreach (['html/popup.php', 'tools/popup_ajax.php', 'tools/popup_group_ajax.php'] as $relativePath) {
        file_put_contents($asproRoot . '/' . $relativePath, $popupSource);
    }

    return [$asproRoot, $target, $storage, $source, $popupSource];
}

$fixtureRoot = sys_get_temp_dir() . '/pwcalc-aspro-patch-' . bin2hex(random_bytes(8));

try {
    [$asproRoot, $target, $storage, $source] = createPatchFixture($fixtureRoot);
    $manager = new AsproAiPatchManager($fixtureRoot, $asproRoot, $storage, PHP_BINARY);

    $status = $manager->getStatus();
    assertPatch($status['state'] === 'not_installed', 'Fresh supported source must be installable.');

    $installed = $manager->apply();
    $patchedSource = (string)file_get_contents($target);
    assertPatch($installed['state'] === 'installed' && $installed['changed'] === true, 'First apply must install patch.');
    assertPatch(substr_count($patchedSource, 'PROSPEKTWEB.CALC ASPRO AI PATCH BEGIN') === 1, 'Patch marker must be unique.');
    foreach (['html/popup.php', 'tools/popup_ajax.php', 'tools/popup_group_ajax.php'] as $relativePath) {
        $popup = (string)file_get_contents($asproRoot . '/' . $relativePath);
        assertPatch(substr_count($popup, 'value="ChatGPT" selected data-prospektweb-calc-default="true"') === 1, 'ChatGPT must be selected in ' . $relativePath . '.');
    }
    assertPatch(is_file($storage . '/state.json'), 'Patch state must be persisted.');

    $secondApply = $manager->apply();
    assertPatch($secondApply['changed'] === false, 'Second apply must be idempotent.');
    assertPatch(hash('sha256', $patchedSource) === hash_file('sha256', $target), 'Idempotent apply must not rewrite source.');

    file_put_contents($target, $source);
    $overwritten = $manager->getStatus();
    assertPatch($overwritten['state'] === 'overwritten' && $overwritten['canApply'] === true, 'Vendor update overwrite must be repairable.');
    $reinstalled = $manager->apply();
    assertPatch($reinstalled['state'] === 'installed' && $reinstalled['changed'] === true, 'Patch must reinstall after vendor overwrite.');

    file_put_contents($target, (string)file_get_contents($target) . "// external change\n");
    $external = $manager->getStatus();
    assertPatch($external['state'] === 'external_changes', 'Changes after patch must be detected.');
    $refusedRemoval = $manager->remove();
    assertPatch($refusedRemoval['changed'] === false, 'Removal must refuse to overwrite external changes.');
    assertPatch(strpos((string)file_get_contents($target), '// external change') !== false, 'External changes must remain intact.');

    $cleanRoot = $fixtureRoot . '-clean';
    [$cleanAsproRoot, $cleanTarget, $cleanStorage, $cleanSource] = createPatchFixture($cleanRoot);
    $cleanManager = new AsproAiPatchManager($cleanRoot, $cleanAsproRoot, $cleanStorage, PHP_BINARY);
    $cleanManager->apply();
    $removed = $cleanManager->remove();
    assertPatch($removed['changed'] === true, 'Clean managed patch must be removable.');
    assertPatch(hash('sha256', $cleanSource) === hash_file('sha256', $cleanTarget), 'Removal must restore exact original SHA-256.');
    foreach (['html/popup.php', 'tools/popup_ajax.php', 'tools/popup_group_ajax.php'] as $relativePath) {
        $popup = (string)file_get_contents($cleanAsproRoot . '/' . $relativePath);
        assertPatch(strpos($popup, 'data-prospektweb-calc-default') === false, 'Removal must restore ' . $relativePath . '.');
    }
    assertPatch($cleanManager->getStatus()['state'] === 'not_installed', 'Clean removal must reset status.');

    $unsupportedRoot = $fixtureRoot . '-unsupported';
    [$unsupportedAsproRoot, , $unsupportedStorage] = createPatchFixture($unsupportedRoot, '2.0.0');
    $unsupportedManager = new AsproAiPatchManager($unsupportedRoot, $unsupportedAsproRoot, $unsupportedStorage, PHP_BINARY);
    assertPatch($unsupportedManager->getStatus()['state'] === 'unsupported_version', 'Unknown Aspro version must be rejected.');

    $legacyRoot = $fixtureRoot . '-legacy';
    [$legacyAsproRoot, $legacyTarget, $legacyStorage, , $legacyPopupSource] = createPatchFixture($legacyRoot);
    $legacyManager = new AsproAiPatchManager($legacyRoot, $legacyAsproRoot, $legacyStorage, PHP_BINARY);
    $legacyManager->apply();
    $newState = json_decode((string)file_get_contents($legacyStorage . '/state.json'), true);
    foreach (['html/popup.php', 'tools/popup_ajax.php', 'tools/popup_group_ajax.php'] as $relativePath) {
        file_put_contents($legacyAsproRoot . '/' . $relativePath, $legacyPopupSource);
    }
    $legacyChatState = $newState['files']['lib/services/chatgpt.php'];
    file_put_contents(
        $legacyStorage . '/state.json',
        json_encode([
            'patchId' => AsproAiPatchManager::PATCH_ID,
            'patchVersion' => '1.0.0',
            'asproModuleId' => AsproAiPatchManager::ASPRO_MODULE_ID,
            'asproVersion' => AsproAiPatchManager::SUPPORTED_ASPRO_VERSION,
            'targetFile' => $legacyTarget,
            'originalSha256' => $legacyChatState['originalSha256'],
            'patchedSha256' => $legacyChatState['patchedSha256'],
            'backupFile' => $legacyChatState['backupFile'],
            'installedAt' => gmdate('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
    assertPatch($legacyManager->getStatus()['state'] === 'upgrade_available', 'Legacy 1.0.0 state must be upgradeable.');
    $legacyUpgrade = $legacyManager->apply();
    assertPatch($legacyUpgrade['state'] === 'installed' && $legacyUpgrade['changed'] === true, 'Legacy patch must upgrade safely.');
    $legacyRemoved = $legacyManager->remove();
    assertPatch($legacyRemoved['changed'] === true && $legacyManager->getStatus()['state'] === 'not_installed', 'Upgraded legacy patch must remain removable.');

    echo "Aspro AI patch manager tests passed.\n";
} finally {
    removePatchFixture($fixtureRoot);
    removePatchFixture($fixtureRoot . '-clean');
    removePatchFixture($fixtureRoot . '-unsupported');
    removePatchFixture($fixtureRoot . '-legacy');
}
