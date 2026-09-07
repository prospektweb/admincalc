<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/lib/Diagnostic/GitHubClient.php';
use Prospektweb\Calc\Diagnostic\GitHubClient;
if (GitHubClient::GITHUB_API_BASE !== 'https://api.github.com/repos/prospektweb/admincalc') {
    throw new RuntimeException('Diagnostics must use the canonical module repository.');
}
$source=file_get_contents(dirname(__DIR__).'/lib/Diagnostic/GitHubClient.php');
foreach (["CURLOPT_FOLLOWLOCATION => false", "'follow_location' => 0"] as $guard) {
    if (!str_contains($source,$guard)) throw new RuntimeException('Both transports must refuse unverified token redirects.');
}
echo "GitHub canonical destination and redirect guards PASS\n";
