<?php
namespace PedimentAi\Tests\BlockTree;

use PedimentAi\BlockTree\Serializer;

class SerializerTest extends \WP_UnitTestCase {
	public function test_serializes_single_block(): void {
		$markup = ( new Serializer() )->serialize( [
			[
				'name'        => 'pediment/hero',
				'attributes'  => [ 'headline' => 'Hi' ],
				'innerBlocks' => [],
			],
		] );

		$this->assertStringContainsString( '<!-- wp:pediment/hero', $markup );
		$this->assertStringContainsString( '"headline":"Hi"', $markup );
		$this->assertStringContainsString( '/-->', $markup );
	}

	public function test_serializes_nested_blocks(): void {
		$markup = ( new Serializer() )->serialize( [
			[
				'name'        => 'pediment/faq',
				'attributes'  => [],
				'innerBlocks' => [
					[ 'name' => 'pediment/faq-item', 'attributes' => [ 'question' => 'Q', 'answer' => 'A' ], 'innerBlocks' => [] ],
				],
			],
		] );

		$this->assertStringContainsString( '<!-- wp:pediment/faq -->',       $markup );
		$this->assertStringContainsString( '<!-- wp:pediment/faq-item',      $markup );
		$this->assertStringContainsString( '<!-- /wp:pediment/faq -->',      $markup );
	}

	public function test_returns_empty_string_for_empty_tree(): void {
		$this->assertSame( '', ( new Serializer() )->serialize( [] ) );
	}
}
