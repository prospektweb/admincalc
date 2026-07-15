<?php
require_once __DIR__ . '/../lib/Service/CalcServerClient.php';
require_once __DIR__ . '/../lib/Service/CalcServerBatchResultValidator.php';
require_once __DIR__ . '/../lib/Service/CalcServerBatchProcessor.php';

use Prospektweb\Frontcalc\Service\CalcServerBatchProcessor;
use Prospektweb\Frontcalc\Service\CalcServerClient;

function bp_same($expected, $actual, string $message): void { if ($expected !== $actual) throw new RuntimeException($message); }
function bp_selected(array $ids): array { return array_map(static fn(int $id): array => ['id' => $id, 'properties' => ['marker' => 'offer_' . $id]], $ids); }
function bp_item(int $id): array { return ['offer_id'=>$id,'purchase_price'=>100+abs($id),'direct_purchase_price'=>null,'currency'=>'RUB','width'=>null,'length'=>null,'height'=>null,'weight'=>null,'parametr_values'=>[]]; }

class RealtimeFakeClient extends CalcServerClient
{
    public array $responses; public array $calls = [];
    public function __construct(array $responses) { $this->responses = $responses; }
    public function calculate(string $baseUrl, int $timeout, array $payload): array {
        $this->calls[] = $payload;
        $response = array_shift($this->responses);
        return is_callable($response) ? $response($payload) : $response;
    }
}
$success = static function (array $payload): array { return ['success'=>true,'data'=>array_map(static fn(array $offer):array=>bp_item((int)$offer['id']),$payload['selectedOffers']),'meta'=>['duration_ms'=>1],'warnings'=>[]]; };
$processor = new CalcServerBatchProcessor();
$base = ['productId'=>10];
$client = new RealtimeFakeClient([$success,$success,$success]);
$result = $processor->process($base,bp_selected([-1,-2,-3,-4,-5]),'http://calc',10,2,$client);
bp_same([2,2,1],array_map(static fn(array $call):int=>count($call['selectedOffers']),$client->calls),'batch split');
bp_same(5,count($result['items']),'all realtime items');
bp_same(3,$result['meta']['successful_batches'],'successful batches');

$client = new RealtimeFakeClient([['success'=>true,'data'=>[bp_item(-1)],'meta'=>['duration_ms'=>1],'warnings'=>[]],['success'=>false,'data'=>[],'meta'=>['duration_ms'=>1],'warnings'=>[],'error'=>['message'=>'failed']]]);
$result = $processor->process($base,bp_selected([-1,-2,-3,-4]),'http://calc',10,2,$client);
bp_same(1,count($result['items']),'partial data retained');
bp_same(1,$result['meta']['partial_batches'],'partial counter');
bp_same(1,$result['meta']['failed_batches'],'failed counter');
bp_same(2,count($client->calls),'every batch requested in realtime');
echo "CalcServerBatchProcessor tests passed\n";
