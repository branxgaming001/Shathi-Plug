<?php
namespace NeerMedia\Sathi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use NeerMedia\Sathi\Core\Data\FunctionCall;
use NeerMedia\Sathi\Core\Data\FunctionResult;

class FunctionCallTest extends TestCase {

    public function test_from_openai(): void {
        $call = FunctionCall::from_openai( [
            'id'       => 'call_abc123',
            'function' => [
                'name'      => 'search_kb',
                'arguments' => '{"query":"test"}',
            ],
        ] );

        $this->assertEquals( 'call_abc123', $call->id );
        $this->assertEquals( 'search_kb', $call->name );
        $this->assertEquals( '{"query":"test"}', $call->arguments );
    }

    public function test_from_anthropic(): void {
        $call = FunctionCall::from_anthropic( [
            'id'    => 'tool_001',
            'name'  => 'search',
            'input' => [ 'query' => 'testing' ],
        ] );

        $this->assertEquals( 'tool_001', $call->id );
        $this->assertEquals( 'search', $call->name );
        $this->assertStringContainsString( 'query', $call->arguments );
    }

    public function test_args_decoding(): void {
        $call = new FunctionCall( 'id1', 'test', '{"key":"val","num":42}' );
        $args = $call->args();
        $this->assertEquals( 'val', $args['key'] );
        $this->assertEquals( 42, $args['num'] );
    }

    public function test_empty_args(): void {
        $call = new FunctionCall( 'id1', 'test', '' );
        $this->assertEquals( [], $call->args() );
    }

    public function test_result_success(): void {
        $result = FunctionResult::success( 'call_1', [ 'found' => true ] );
        $this->assertTrue( $result->success );
        $this->assertNull( $result->error );
        $this->assertStringContainsString( 'found', $result->as_string() );
    }

    public function test_result_failure(): void {
        $result = FunctionResult::failure( 'call_1', 'Tool not found' );
        $this->assertFalse( $result->success );
        $this->assertEquals( 'Tool not found', $result->error );
        $this->assertStringContainsString( 'Error:', $result->as_string() );
    }

    public function test_to_openai_format(): void {
        $result = FunctionResult::success( 'call_1', 'OK' );
        $fmt = $result->to_openai_format();
        $this->assertEquals( 'function_call_output', $fmt['type'] );
        $this->assertEquals( 'call_1', $fmt['call_id'] );
    }

    public function test_to_anthropic_format(): void {
        $result = FunctionResult::success( 'tool_1', 'Result text' );
        $fmt = $result->to_anthropic_format();
        $this->assertEquals( 'tool_result', $fmt['type'] );
        $this->assertEquals( 'tool_1', $fmt['tool_use_id'] );
    }
}
