<?php

namespace Prospektweb\Calc\Install;

/**
 * Safely updates the explicit property-code arrays of the public Aspro catalog
 * component when calculator properties move from products to SKU offers.
 *
 * /catalog/index.php is site-owned (it is not an installer asset), therefore
 * every write is guarded by the SHA-256 returned by audit(), backed up, linted,
 * recorded, and reversible. The global Bitrix property-features switch is not
 * touched.
 */
final class CatalogDisplayConfigPatcher
{
    public const PATCH_ID = 'prospektweb.calc.catalog-display-config/v1';
    public const TARGET_RELATIVE_PATH = 'catalog/index.php';

    /** @var string[] */
    private const PRODUCT_DISPLAY_PARAMS = [
        'FILTER_PROPERTY_CODE',
        'COMPARE_PROPERTY_CODE',
        'LIST_PROPERTY_CODE',
        'DETAIL_PROPERTY_CODE',
        'TOP_PROPERTY_CODE',
        'CUSTOM_PROPERTY_DATA',
    ];

    /** @var string[] */
    private const OFFER_DISPLAY_PARAMS = [
        'FILTER_OFFERS_PROPERTY_CODE',
        'COMPARE_OFFERS_PROPERTY_CODE',
        'LIST_OFFERS_PROPERTY_CODE',
        'DETAIL_OFFERS_PROPERTY_CODE',
        'TOP_OFFERS_PROPERTY_CODE',
        'SKU_PROPERTY_CODE',
    ];

    /** @var string[] */
    private const PRESERVE_PARAMS = [
        'OFFER_TREE_PROPS',
        'SKU_TREE_PROPS',
        'OFFERS_CART_PROPERTIES',
    ];

    /** @var string[] */
    private const MOVED_PRODUCT_CODES = [
        'CALC_METHOD',
        'CALC_FILLING',
        'CALC_FORMAT',
        'CALC_TYPE_PAPER',
        'CALC_TYPE_BASE',
        'CALC_PROTECTION',
        'CALC_ADD_OPTIONS',
        'CALC_BINDING',
    ];

    /**
     * Ordered by the offer-iblock SORT contract. CALC_PROP_VOLUME is not added
     * here: it is a quantity input and any existing explicit use is preserved.
     *
     * @var string[]
     */
    private const VISIBLE_OFFER_CODES = [
        'CALC_PROP_METHOD',
        'CALC_PROP_COLOR_SCHEME',
        'CALC_PROP_BLOCK_COLOR_SCHEME',
        'CALC_PROP_COVER_COLOR_SCHEME',
        'CALC_PROP_TYPE_PAPER',
        'CALC_PROP_TYPE_BASE',
        'CALC_PROP_DENSITY_PAPER',
        'CALC_PROP_BLOCK_DENSITY_PAPER',
        'CALC_PROP_COVER_DENSITY_PAPER',
        'CALC_PROP_COLOR',
        'CALC_PROP_FORMAT',
        'CALC_PROP_FILLING',
        'CALC_PROP_SHEETS',
        'CALC_PROP_STRIPS',
        'CALC_QTY_ITEMS',
        'CALC_PROP_BINDING',
        'CALC_PROP_PROTECTION',
        'CALC_PROP_LAMINATION',
        'CALC_PROP_LAMINATION_SIDES',
        'CALC_PROP_OPTIONS',
    ];

    /** @var string[] */
    private const OBSOLETE_OFFER_ALIASES = [
        'CALC_LAMINATION',
        'CALC_LAMINATION_SIDES',
        'CALC_COLORS',
        'CALC_COLOR',
    ];

    private const HIDDEN_CODES = [
        'CALC_STATE_HASH',
    ];

    /** @var string */
    private $documentRoot;

    /** @var string */
    private $targetFile;

    /** @var string */
    private $storageRoot;

    /** @var string */
    private $phpBinary;

    public function __construct(
        ?string $documentRoot = null,
        ?string $storageRoot = null,
        ?string $phpBinary = null
    ) {
        $root = $documentRoot ?? (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
        $this->documentRoot = $this->normalizePath($root);
        $this->targetFile = $this->documentRoot . '/' . self::TARGET_RELATIVE_PATH;
        $this->storageRoot = $this->normalizePath(
            $storageRoot ?? ($this->documentRoot . '/bitrix/backup/prospektweb.calc/catalog-display-config')
        );
        $this->phpBinary = $phpBinary ?? (defined('PHP_BINARY') ? (string)PHP_BINARY : 'php');
    }

    /** @return string[] */
    public static function visibleOfferCodes(): array
    {
        return self::VISIBLE_OFFER_CODES;
    }

    /** @return string[] */
    public static function movedProductCodes(): array
    {
        return self::MOVED_PRODUCT_CODES;
    }

    /**
     * Pure source transformation used by audit, apply and regression tests.
     *
     * @return array{source:string,changed:bool,currentSha256:string,patchedSha256:string,parameters:array<string,string[]>}
     */
    public static function patchSource(string $source): array
    {
        $parameterNames = array_merge(
            self::PRODUCT_DISPLAY_PARAMS,
            self::OFFER_DISPLAY_PARAMS,
            self::PRESERVE_PARAMS
        );
        $locations = [];
        $parameters = [];
        foreach ($parameterNames as $parameterName) {
            $location = self::locateParameterArray($source, $parameterName);
            $values = self::parseArrayLiteral(substr(
                $source,
                $location['literalStart'],
                $location['literalEnd'] - $location['literalStart']
            ), $parameterName);
            $locations[$parameterName] = $location;
            $parameters[$parameterName] = $values;
        }

        $patchedParameters = $parameters;
        foreach (self::PRODUCT_DISPLAY_PARAMS as $parameterName) {
            $patchedParameters[$parameterName] = self::withoutCodes(
                $parameters[$parameterName],
                array_merge(self::MOVED_PRODUCT_CODES, self::HIDDEN_CODES)
            );
        }
        foreach (self::OFFER_DISPLAY_PARAMS as $parameterName) {
            $patchedParameters[$parameterName] = self::mergeVisibleOfferCodes($parameters[$parameterName]);
        }
        foreach (self::PRESERVE_PARAMS as $parameterName) {
            // Never turn calculator characteristics into SKU selectors or
            // basket identity fields. Only a technical hash is forbidden.
            $patchedParameters[$parameterName] = self::withoutCodes(
                $parameters[$parameterName],
                self::HIDDEN_CODES
            );
        }

        $replacements = [];
        foreach ($parameterNames as $parameterName) {
            if ($parameters[$parameterName] === $patchedParameters[$parameterName]) {
                continue;
            }
            $location = $locations[$parameterName];
            $replacements[] = [
                'start' => $location['literalStart'],
                'length' => $location['literalEnd'] - $location['literalStart'],
                'replacement' => self::renderArrayLiteral(
                    $patchedParameters[$parameterName],
                    $location['indent'],
                    $location['style']
                ),
            ];
        }
        usort($replacements, static function (array $left, array $right): int {
            return $right['start'] <=> $left['start'];
        });
        $patched = $source;
        foreach ($replacements as $replacement) {
            $patched = substr_replace(
                $patched,
                $replacement['replacement'],
                $replacement['start'],
                $replacement['length']
            );
        }

        self::assertPatchedContract($patched);
        return [
            'source' => $patched,
            'changed' => $patched !== $source,
            'currentSha256' => hash('sha256', $source),
            'patchedSha256' => hash('sha256', $patched),
            'parameters' => $patchedParameters,
        ];
    }

    /** @return array<string,mixed> */
    public function audit(): array
    {
        $this->assertSafeTarget();
        $source = $this->readTarget();
        $plan = self::patchSource($source);
        $state = $this->readState();
        $managed = is_array($state)
            && hash_equals((string)($state['patchedSha256'] ?? ''), $plan['currentSha256']);

        return [
            'status' => 'ok',
            'patchId' => self::PATCH_ID,
            'targetFile' => $this->targetFile,
            'currentSha256' => $plan['currentSha256'],
            'patchedSha256' => $plan['patchedSha256'],
            'changed' => $plan['changed'],
            'managed' => $managed,
            'parameters' => $plan['parameters'],
        ];
    }

    /** @return array<string,mixed> */
    public function apply(string $expectedCurrentSha256): array
    {
        $this->assertSha256($expectedCurrentSha256);
        $this->assertSafeTarget();
        $lock = $this->openLock();
        try {
            $this->assertSafeTarget();
            $original = $this->readTarget();
            $currentSha256 = hash('sha256', $original);
            if (!hash_equals(strtolower($expectedCurrentSha256), $currentSha256)) {
                throw new \RuntimeException('Catalog display config changed after audit; run audit again.');
            }
            $plan = self::patchSource($original);
            if (!$plan['changed']) {
                $result = $this->audit();
                $result['changed'] = false;
                return $result;
            }

            $this->ensureStorage();
            $backupFile = $this->storageRoot . '/backups/catalog-index.'
                . gmdate('Ymd-His') . '.' . substr($currentSha256, 0, 12) . '.php';
            $this->atomicWrite($backupFile, $original, 0600);
            if (!hash_equals($currentSha256, (string)hash_file('sha256', $backupFile))) {
                throw new \RuntimeException('Catalog display config backup failed integrity verification.');
            }
            $lint = $this->lintSource($plan['source']);
            if (!$lint['success']) {
                throw new \RuntimeException('Patched catalog display config failed PHP lint: ' . $lint['message']);
            }

            $mode = (int)(fileperms($this->targetFile) & 0777);
            try {
                $this->atomicWrite($this->targetFile, $plan['source'], $mode ?: 0644);
                if (!hash_equals($plan['patchedSha256'], (string)hash_file('sha256', $this->targetFile))) {
                    throw new \RuntimeException('Patched catalog display config failed integrity verification.');
                }
                $this->writeState([
                    'patchId' => self::PATCH_ID,
                    'targetFile' => $this->targetFile,
                    'originalSha256' => $currentSha256,
                    'patchedSha256' => $plan['patchedSha256'],
                    'backupFile' => $backupFile,
                    'appliedAt' => gmdate('c'),
                ]);
            } catch (\Throwable $error) {
                $this->atomicWrite($this->targetFile, $original, $mode ?: 0644);
                throw $error;
            }

            $result = $this->audit();
            $result['changed'] = true;
            $result['backupFile'] = $backupFile;
            return $result;
        } finally {
            $this->closeLock($lock);
        }
    }

    /** @return array<string,mixed> */
    public function rollback(string $expectedPatchedSha256): array
    {
        $this->assertSha256($expectedPatchedSha256);
        $this->assertSafeTarget();
        $lock = $this->openLock();
        try {
            $this->assertSafeTarget();
            $state = $this->readState();
            if (!is_array($state) || (string)($state['patchId'] ?? '') !== self::PATCH_ID) {
                throw new \RuntimeException('Managed catalog display config state was not found.');
            }
            $currentSha256 = (string)hash_file('sha256', $this->targetFile);
            if (!hash_equals(strtolower($expectedPatchedSha256), $currentSha256)
                || !hash_equals((string)($state['patchedSha256'] ?? ''), $currentSha256)) {
                throw new \RuntimeException('Refusing to overwrite externally changed catalog display config.');
            }
            $backupFile = $this->normalizePath((string)($state['backupFile'] ?? ''));
            if (!$this->isSafeStorageFile($backupFile) || !is_file($backupFile)) {
                throw new \RuntimeException('Verified catalog display config backup was not found.');
            }
            $original = (string)file_get_contents($backupFile);
            $originalSha256 = hash('sha256', $original);
            if (!hash_equals((string)($state['originalSha256'] ?? ''), $originalSha256)) {
                throw new \RuntimeException('Catalog display config backup checksum mismatch.');
            }
            $lint = $this->lintSource($original);
            if (!$lint['success']) {
                throw new \RuntimeException('Catalog display config backup failed PHP lint.');
            }
            $mode = (int)(fileperms($this->targetFile) & 0777);
            $this->atomicWrite($this->targetFile, $original, $mode ?: 0644);
            if (!hash_equals($originalSha256, (string)hash_file('sha256', $this->targetFile))) {
                throw new \RuntimeException('Restored catalog display config failed integrity verification.');
            }
            $this->deleteState();
            return [
                'status' => 'ok',
                'patchId' => self::PATCH_ID,
                'targetFile' => $this->targetFile,
                'changed' => true,
                'currentSha256' => $originalSha256,
            ];
        } finally {
            $this->closeLock($lock);
        }
    }

    private static function assertPatchedContract(string $source): void
    {
        foreach (self::PRODUCT_DISPLAY_PARAMS as $parameterName) {
            $values = self::readParameterValues($source, $parameterName);
            foreach (array_merge(self::MOVED_PRODUCT_CODES, self::HIDDEN_CODES) as $code) {
                if (in_array($code, $values, true)) {
                    throw new \RuntimeException($parameterName . ' still contains forbidden product code ' . $code . '.');
                }
            }
        }
        foreach (self::OFFER_DISPLAY_PARAMS as $parameterName) {
            $values = self::readParameterValues($source, $parameterName);
            foreach (self::VISIBLE_OFFER_CODES as $code) {
                if (count(array_keys($values, $code, true)) !== 1) {
                    throw new \RuntimeException($parameterName . ' must contain exactly one ' . $code . '.');
                }
            }
            foreach (array_merge(self::OBSOLETE_OFFER_ALIASES, self::HIDDEN_CODES) as $code) {
                if (in_array($code, $values, true)) {
                    throw new \RuntimeException($parameterName . ' contains obsolete or hidden code ' . $code . '.');
                }
            }
        }
        foreach (self::PRESERVE_PARAMS as $parameterName) {
            $values = self::readParameterValues($source, $parameterName);
            foreach (self::HIDDEN_CODES as $code) {
                if (in_array($code, $values, true)) {
                    throw new \RuntimeException($parameterName . ' contains hidden code ' . $code . '.');
                }
            }
        }
    }

    /** @return string[] */
    private static function readParameterValues(string $source, string $parameterName): array
    {
        $location = self::locateParameterArray($source, $parameterName);
        return self::parseArrayLiteral(substr(
            $source,
            $location['literalStart'],
            $location['literalEnd'] - $location['literalStart']
        ), $parameterName);
    }

    /** @return string[] */
    private static function mergeVisibleOfferCodes(array $values): array
    {
        $remove = array_merge(
            self::VISIBLE_OFFER_CODES,
            self::OBSOLETE_OFFER_ALIASES,
            self::HIDDEN_CODES,
            self::MOVED_PRODUCT_CODES
        );
        $firstIndex = null;
        foreach ($values as $index => $value) {
            if (in_array($value, array_merge(self::VISIBLE_OFFER_CODES, self::OBSOLETE_OFFER_ALIASES), true)) {
                $firstIndex = $index;
                break;
            }
        }
        $insertionIndex = null;
        if ($firstIndex !== null) {
            $insertionIndex = 0;
            foreach (array_slice($values, 0, $firstIndex) as $value) {
                if (!in_array($value, $remove, true)) {
                    $insertionIndex++;
                }
            }
        }
        $filtered = self::withoutCodes($values, $remove);
        $insertionIndex = $insertionIndex ?? count($filtered);
        array_splice($filtered, $insertionIndex, 0, self::VISIBLE_OFFER_CODES);
        return self::uniqueValues($filtered);
    }

    /** @return string[] */
    private static function withoutCodes(array $values, array $codes): array
    {
        return self::uniqueValues(array_values(array_filter(
            $values,
            static function (string $value) use ($codes): bool {
                return !in_array($value, $codes, true);
            }
        )));
    }

    /** @return string[] */
    private static function uniqueValues(array $values): array
    {
        $result = [];
        $seen = [];
        foreach ($values as $value) {
            $value = (string)$value;
            if (isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $result[] = $value;
        }
        return $result;
    }

    /**
     * @return array{literalStart:int,literalEnd:int,indent:string,style:string}
     */
    private static function locateParameterArray(string $source, string $parameterName): array
    {
        [$tokenSource, $offsetInsertions] = self::tokenizableSource($source);
        $tokens = token_get_all($tokenSource);
        $offset = 0;
        $matches = [];
        $count = count($tokens);
        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            $text = is_array($token) ? $token[1] : $token;
            $tokenOffset = self::mapTokenOffsetToSource($offset, $offsetInsertions);
            $offset += strlen($text);
            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING
                || self::decodeStringToken($text) !== $parameterName) {
                continue;
            }
            $arrowIndex = self::nextSignificantToken($tokens, $index + 1);
            if ($arrowIndex === null || !is_array($tokens[$arrowIndex]) || $tokens[$arrowIndex][0] !== T_DOUBLE_ARROW) {
                continue;
            }
            $literalIndex = self::nextSignificantToken($tokens, $arrowIndex + 1);
            if ($literalIndex === null) {
                continue;
            }
            $literalToken = $tokens[$literalIndex];
            $literalText = is_array($literalToken) ? $literalToken[1] : $literalToken;
            $literalOffset = 0;
            for ($scan = 0; $scan < $literalIndex; $scan++) {
                $literalOffset += strlen(is_array($tokens[$scan]) ? $tokens[$scan][1] : $tokens[$scan]);
            }
            $literalOffset = self::mapTokenOffsetToSource($literalOffset, $offsetInsertions);
            $style = '';
            $openingOffset = $literalOffset;
            if ($literalText === '[') {
                $style = 'short';
            } elseif (is_array($literalToken) && $literalToken[0] === T_ARRAY) {
                $parenthesisIndex = self::nextSignificantToken($tokens, $literalIndex + 1);
                if ($parenthesisIndex === null || $tokens[$parenthesisIndex] !== '(') {
                    continue;
                }
                $openingOffset = 0;
                for ($scan = 0; $scan < $parenthesisIndex; $scan++) {
                    $openingOffset += strlen(is_array($tokens[$scan]) ? $tokens[$scan][1] : $tokens[$scan]);
                }
                $openingOffset = self::mapTokenOffsetToSource($openingOffset, $offsetInsertions);
                $style = 'long';
            } else {
                continue;
            }
            $closingOffset = self::findClosingBracket(
                $source,
                $openingOffset,
                $style === 'short' ? '[' : '(',
                $style === 'short' ? ']' : ')'
            );
            $lineStart = strrpos(substr($source, 0, $tokenOffset), "\n");
            $lineStart = $lineStart === false ? 0 : $lineStart + 1;
            $indentCandidate = substr($source, $lineStart, $tokenOffset - $lineStart);
            $indent = preg_match('/^[\t ]*$/D', $indentCandidate) ? $indentCandidate : '';
            $literalStart = $style === 'short' ? $openingOffset : $literalOffset;
            $matches[] = [
                'literalStart' => $literalStart,
                'literalEnd' => $closingOffset + 1,
                'indent' => $indent,
                'style' => $style,
            ];
        }
        if (count($matches) !== 1) {
            throw new \RuntimeException(
                'Expected exactly one explicit ' . $parameterName . ' array, found ' . count($matches) . '.'
            );
        }
        return $matches[0];
    }

    /** @return array{0:string,1:array<int,array{end:int,delta:int}>} */
    private static function tokenizableSource(string $source): array
    {
        // The production catalog entry point intentionally uses Bitrix's
        // legacy short opening tag. Local CLI installations often disable
        // short_open_tag, so normalize every short tag only in the
        // tokenizer/linter copy and retain a byte-offset translation table.
        if (!preg_match_all('/<\?(?!php\b|=)/i', $source, $matches, PREG_OFFSET_CAPTURE)) {
            return [$source, []];
        }
        $result = '';
        $cursor = 0;
        $insertions = [];
        foreach ($matches[0] as $match) {
            $position = (int)$match[1];
            $result .= substr($source, $cursor, $position - $cursor) . '<?php ';
            $insertions[] = ['end' => strlen($result), 'delta' => 4];
            $cursor = $position + 2;
        }
        $result .= substr($source, $cursor);
        return [$result, $insertions];
    }

    /** @param array<int,array{end:int,delta:int}> $insertions */
    private static function mapTokenOffsetToSource(int $offset, array $insertions): int
    {
        $mapped = $offset;
        foreach ($insertions as $insertion) {
            if ($offset >= $insertion['end']) {
                $mapped -= $insertion['delta'];
            }
        }
        return max(0, $mapped);
    }

    /** @return int|null */
    private static function nextSignificantToken(array $tokens, int $start): ?int
    {
        $count = count($tokens);
        for ($index = $start; $index < $count; $index++) {
            if (!is_array($tokens[$index])
                || !in_array($tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $index;
            }
        }
        return null;
    }

    private static function findClosingBracket(string $source, int $openingOffset, string $opening, string $closing): int
    {
        $depth = 0;
        $quote = '';
        $escaped = false;
        $length = strlen($source);
        for ($index = $openingOffset; $index < $length; $index++) {
            $character = $source[$index];
            if ($quote !== '') {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ($character === "'" || $character === '"') {
                $quote = $character;
                continue;
            }
            if ($character === $opening) {
                $depth++;
            } elseif ($character === $closing) {
                $depth--;
                if ($depth === 0) {
                    return $index;
                }
            }
        }
        throw new \RuntimeException('Unclosed catalog component parameter array.');
    }

    /** @return string[] */
    private static function parseArrayLiteral(string $literal, string $parameterName): array
    {
        $tokens = token_get_all('<?php return ' . $literal . ';');
        $allowedIds = [
            T_OPEN_TAG, T_RETURN, T_ARRAY, T_LNUMBER, T_CONSTANT_ENCAPSED_STRING,
            T_DOUBLE_ARROW, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT,
        ];
        foreach ($tokens as $token) {
            if (is_array($token)) {
                if (!in_array($token[0], $allowedIds, true)) {
                    throw new \RuntimeException($parameterName . ' is not a static scalar array.');
                }
                continue;
            }
            if (strpos('[](),;', $token) === false) {
                throw new \RuntimeException($parameterName . ' contains an unsupported array token.');
            }
        }
        /** @var mixed $decoded */
        $decoded = eval('return ' . $literal . ';');
        if (!is_array($decoded)) {
            throw new \RuntimeException($parameterName . ' is not an array.');
        }
        $values = [];
        foreach ($decoded as $value) {
            if (!is_string($value)) {
                throw new \RuntimeException($parameterName . ' contains a non-string value.');
            }
            $values[] = $value;
        }
        return $values;
    }

    private static function renderArrayLiteral(array $values, string $indent, string $style): string
    {
        $opening = $style === 'long' ? 'array(' : '[';
        $closing = $style === 'long' ? ')' : ']';
        if ($values === []) {
            return $opening . $closing;
        }
        $lines = [$opening];
        foreach (array_values($values) as $index => $value) {
            if (!preg_match('/^[A-Za-z0-9_]*$/D', (string)$value)) {
                throw new \RuntimeException('Unsupported property code in catalog component parameter.');
            }
            $lines[] = $indent . "\t" . $index . ' => "' . (string)$value . '",';
        }
        $lines[] = $indent . $closing;
        return implode("\n", $lines);
    }

    private static function decodeStringToken(string $token): string
    {
        $quote = $token[0] ?? '';
        $inner = substr($token, 1, -1);
        if ($quote === "'") {
            return str_replace(["\\'", "\\\\"], ["'", "\\"], $inner);
        }
        return stripcslashes($inner);
    }

    private function assertSafeTarget(): void
    {
        if ($this->documentRoot === '' || !is_dir($this->documentRoot)) {
            throw new \RuntimeException('Document root is unavailable.');
        }
        if (!is_file($this->targetFile) || is_link($this->targetFile)) {
            throw new \RuntimeException('Catalog display config target is missing or unsafe.');
        }
        $root = realpath($this->documentRoot);
        $target = realpath($this->targetFile);
        if ($root === false || $target === false
            || $this->normalizePath($target) !== $this->normalizePath($root . '/' . self::TARGET_RELATIVE_PATH)) {
            throw new \RuntimeException('Catalog display config target escaped the document root.');
        }
    }

    private function readTarget(): string
    {
        $source = file_get_contents($this->targetFile);
        if (!is_string($source)) {
            throw new \RuntimeException('Unable to read catalog display config.');
        }
        return $source;
    }

    private function assertSha256(string $sha256): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/Di', $sha256)) {
            throw new \InvalidArgumentException('A valid SHA-256 fingerprint is required.');
        }
    }

    private function ensureStorage(): void
    {
        foreach ([$this->storageRoot, $this->storageRoot . '/backups'] as $directory) {
            if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new \RuntimeException('Unable to create catalog display config storage.');
            }
            @chmod($directory, 0700);
        }
        if (!is_file($this->storageRoot . '/.htaccess')) {
            @file_put_contents($this->storageRoot . '/.htaccess', "Deny from all\n", LOCK_EX);
            @chmod($this->storageRoot . '/.htaccess', 0600);
        }
        if (!is_file($this->storageRoot . '/index.php')) {
            @file_put_contents($this->storageRoot . '/index.php', "<?php http_response_code(404); die();\n", LOCK_EX);
            @chmod($this->storageRoot . '/index.php', 0600);
        }
    }

    /** @return array<string,mixed>|null */
    private function readState(): ?array
    {
        $stateFile = $this->storageRoot . '/state.json';
        if (!is_file($stateFile)) {
            return null;
        }
        $decoded = json_decode((string)file_get_contents($stateFile), true);
        return is_array($decoded) ? $decoded : null;
    }

    private function writeState(array $state): void
    {
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new \RuntimeException('Unable to encode catalog display config state.');
        }
        $this->atomicWrite($this->storageRoot . '/state.json', $json . "\n", 0600);
    }

    private function deleteState(): void
    {
        $stateFile = $this->storageRoot . '/state.json';
        if (is_file($stateFile) && !@unlink($stateFile)) {
            throw new \RuntimeException('Unable to remove catalog display config state.');
        }
    }

    private function isSafeStorageFile(string $path): bool
    {
        $root = rtrim($this->storageRoot, '/') . '/';
        return $path !== '' && strncmp($path, $root, strlen($root)) === 0 && !is_link($path);
    }

    /** @return array{success:bool,message:string} */
    private function lintSource(string $source): array
    {
        $this->ensureStorage();
        $temporary = tempnam($this->storageRoot, '.catalog-display-lint-');
        if ($temporary === false) {
            return ['success' => false, 'message' => 'Unable to create PHP lint fixture.'];
        }
        try {
            [$lintSource] = self::tokenizableSource($source);
            if (file_put_contents($temporary, $lintSource, LOCK_EX) === false) {
                return ['success' => false, 'message' => 'Unable to write PHP lint fixture.'];
            }
            if (!function_exists('exec') || in_array('exec', array_map(
                'trim',
                explode(',', (string)ini_get('disable_functions'))
            ), true)) {
                return ['success' => false, 'message' => 'PHP lint execution is unavailable.'];
            }
            $output = [];
            $code = 1;
            @exec(escapeshellarg($this->phpBinary) . ' -l ' . escapeshellarg($temporary) . ' 2>&1', $output, $code);
            return ['success' => $code === 0, 'message' => trim(implode("\n", $output))];
        } finally {
            @unlink($temporary);
        }
    }

    private function atomicWrite(string $target, string $contents, int $mode): void
    {
        $directory = dirname($target);
        $temporary = tempnam($directory, '.catalog-display-write-');
        if ($temporary === false) {
            throw new \RuntimeException('Unable to create atomic catalog display config write.');
        }
        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
                throw new \RuntimeException('Unable to write temporary catalog display config.');
            }
            @chmod($temporary, $mode);
            if (!@rename($temporary, $target)) {
                if (DIRECTORY_SEPARATOR !== '\\' || !is_file($target)) {
                    throw new \RuntimeException('Unable to atomically replace catalog display config.');
                }
                $previous = $target . '.previous-' . bin2hex(random_bytes(6));
                if (!@rename($target, $previous)) {
                    throw new \RuntimeException('Unable to prepare catalog display config replacement.');
                }
                if (!@rename($temporary, $target)) {
                    @rename($previous, $target);
                    throw new \RuntimeException('Catalog display config replacement failed; original restored.');
                }
                @unlink($previous);
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /** @return resource */
    private function openLock()
    {
        $this->ensureStorage();
        $handle = @fopen($this->storageRoot . '/patch.lock', 'c+');
        if (!is_resource($handle) || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new \RuntimeException('Unable to lock catalog display config patcher.');
        }
        return $handle;
    }

    /** @param resource $handle */
    private function closeLock($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
