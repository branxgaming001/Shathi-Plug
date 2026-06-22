<?php
/**
 * Message DTO unit tests.
 *
 * @package NeerMedia\Sathi\Tests
 */

namespace NeerMedia\Sathi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use NeerMedia\Sathi\Core\Data\Message;

class MessageTest extends TestCase {

    public function test_user_message(): void {
        $msg = Message::user( 'Hello, how are you?' );
        $this->assertEquals( 'user', $msg->role );
        $this->assertEquals( 'Hello, how are you?', $msg->content );
        $this->assertNull( $msg->tool_calls );
        $this->assertNotNull( $msg->created_at );
    }

    public function test_assistant_message(): void {
        $msg = Message::assistant( 'I am doing well!', 5 );
        $this->assertEquals( 'assistant', $msg->role );
        $this->assertEquals( 'I am doing well!', $msg->content );
        $this->assertEquals( 5, $msg->token_count );
    }

    public function test_system_message(): void {
        $msg = Message::system( 'You are a helpful assistant.' );
        $this->assertEquals( 'system', $msg->role );
    }

    public function test_tool_message(): void {
        $msg = Message::tool( 'call_123', 'search_kb', 'Found 3 results' );
        $this->assertEquals( 'tool', $msg->role );
        $this->assertEquals( 'call_123', $msg->tool_result['call_id'] );
        $this->assertEquals( 'search_kb', $msg->tool_result['name'] );
    }

    public function test_to_openai_format(): void {
        $msg = Message::assistant( 'Hello', 2 );
        $format = $msg->to_openai_format();
        $this->assertEquals( 'assistant', $format['role'] );
        $this->assertEquals( 'Hello', $format['content'] );
    }

    public function test_to_anthropic_format(): void {
        $msg = Message::user( 'Hi' );
        $format = $msg->to_anthropic_format();
        $this->assertEquals( 'user', $format['role'] );
        $this->assertEquals( 'Hi', $format['content'] );
    }

    public function test_to_array(): void {
        $msg = Message::user( 'Test' );
        $arr = $msg->to_array();
        $this->assertEquals( 'user', $arr['role'] );
        $this->assertEquals( 'Test', $arr['content'] );
    }
}
