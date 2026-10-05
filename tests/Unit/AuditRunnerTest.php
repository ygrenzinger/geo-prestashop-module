<?php
use LlmsCatalog\Audit\AuditRunner;
use PHPUnit\Framework\TestCase;
final class AuditRunnerTest extends TestCase
{
    public function testBatchAndSampleLimitsAreBounded(): void
    {
        $urls=array_map(static fn($i)=>'https://shop.example/'.$i,range(1,50));
        $report=(new AuditRunner())->run($urls,4,'en-US',0);
        self::assertCount(5,$report->findings);
        self::assertFalse($report->complete);
    }
    public function testCancellationProducesNoPartialFindings(): void
    {
        $report=(new AuditRunner())->run(['https://shop.example/'],4,'en-US',0,true);
        self::assertSame([],$report->findings);
        self::assertFalse($report->complete);
    }
}
