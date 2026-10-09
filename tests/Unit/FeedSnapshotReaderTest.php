<?php
use LlmsCatalog\Audit\FeedSnapshotReader;
use PHPUnit\Framework\TestCase;
final class FeedSnapshotReaderTest extends TestCase
{
    public function testReadsXmlAndTsvWithBoundedFieldSets(): void
    {
        $reader=new FeedSnapshotReader();
        self::assertSame('SKU-1',$reader->read('<rss xmlns:g="http://base.google.com/ns/1.0"><channel><item><g:id>SKU-1</g:id><g:title>Item</g:title></item></channel></rss>','xml')[0]['id']);
        self::assertSame('SKU-2',$reader->read("id\ttitle\nSKU-2\tItem\n",'tsv')[0]['id']);
    }
    public function testRejectsOversizedAndUnknownInput(): void
    {
        $this->expectException(LengthException::class);(new FeedSnapshotReader())->read(str_repeat('x',2097153),'xml');
    }
}
