<?php
namespace StarterAi\Tests\BlockTree;

use StarterAi\BlockTree\Parser;

class ParserTest extends \WP_UnitTestCase {
	public function test_parses_single_block(): void {
		$tree = ( new Parser() )->parse( '<!-- wp:starter/hero {"headline":"Hi"} /-->' );
		$this->assertCount( 1, $tree );
		$this->assertSame( 'starter/hero', $tree[0]['name'] );
		$this->assertSame( 'Hi', $tree[0]['attributes']['headline'] );
		$this->assertSame( [], $tree[0]['innerBlocks'] );
	}

	public function test_parses_nested_blocks(): void {
		$tree = ( new Parser() )->parse(
			'<!-- wp:starter/faq -->' .
			'<!-- wp:starter/faq-item {"question":"Q","answer":"A"} /-->' .
			'<!-- /wp:starter/faq -->'
		);
		$this->assertSame( 'starter/faq', $tree[0]['name'] );
		$this->assertCount( 1, $tree[0]['innerBlocks'] );
		$this->assertSame( 'starter/faq-item', $tree[0]['innerBlocks'][0]['name'] );
		$this->assertSame( 'Q', $tree[0]['innerBlocks'][0]['attributes']['question'] );
	}

	public function test_filters_out_freeform_whitespace_blocks(): void {
		$tree = ( new Parser() )->parse(
			"\n\n<!-- wp:starter/hero /-->\n\n<!-- wp:starter/cta /-->\n"
		);
		$this->assertCount( 2, $tree );
	}

	public function test_returns_empty_array_for_empty_content(): void {
		$this->assertSame( [], ( new Parser() )->parse( '' ) );
	}
}
