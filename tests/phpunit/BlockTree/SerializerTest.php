<?php
namespace StarterAi\Tests\BlockTree;

use StarterAi\BlockTree\Serializer;

class SerializerTest extends \WP_UnitTestCase {
	public function test_serializes_single_block(): void {
		$markup = ( new Serializer() )->serialize( [
			[
				'name'        => 'starter/hero',
				'attributes'  => [ 'headline' => 'Hi' ],
				'innerBlocks' => [],
			],
		] );

		$this->assertStringContainsString( '<!-- wp:starter/hero', $markup );
		$this->assertStringContainsString( '"headline":"Hi"', $markup );
		$this->assertStringContainsString( '/-->', $markup );
	}

	public function test_serializes_nested_blocks(): void {
		$markup = ( new Serializer() )->serialize( [
			[
				'name'        => 'starter/faq',
				'attributes'  => [],
				'innerBlocks' => [
					[ 'name' => 'starter/faq-item', 'attributes' => [ 'question' => 'Q', 'answer' => 'A' ], 'innerBlocks' => [] ],
				],
			],
		] );

		$this->assertStringContainsString( '<!-- wp:starter/faq -->',       $markup );
		$this->assertStringContainsString( '<!-- wp:starter/faq-item',      $markup );
		$this->assertStringContainsString( '<!-- /wp:starter/faq -->',      $markup );
	}

	public function test_returns_empty_string_for_empty_tree(): void {
		$this->assertSame( '', ( new Serializer() )->serialize( [] ) );
	}
}
