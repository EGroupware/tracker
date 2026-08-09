<?php
/**
 * EGroupware Tracker - tests for tracker_mailhandler's Api\Mail-touching methods
 *
 * @link http://www.egroupware.org
 * @package tracker
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Tracker;

require_once realpath(__DIR__ . '/../../api/tests/AppTest.php');    // Application test base

use EGroupware\Api;
use EGroupware\Api\AppTest;
use EGroupware\Api\Mail;

/**
 * Mail::get_mailcontent() is called as $mailClass::get_mailcontent(...) - a static call
 * dispatched via late static binding on whatever object is passed in. PHPUnit 12's mock
 * objects explicitly refuse static calls ("Static method ... cannot be invoked on mock
 * object"), so a PHPUnit mock can't be used for that one code path - a real (trivial)
 * subclass is needed instead, giving get_mailcontent() a genuine Mail-typed $mailClass to
 * dispatch onto while still fully controlling what each instance method returns.
 */
class MailHandlerTestDouble extends Mail
{
	public array $sortedHeaders = [];
	public array $headerData = [];
	public array $bodyParts = [];
	public array $attachments = [];
	public bool $sentFolder = false;
	public array $flagCalls = [];

	public function __construct() {}

	function getHeaders($_folderName, $_startMessage, $_numberOfMessages, $_sort, $_reverse, $_filter,
		$_thisUIDOnly = null, $_cacheResult = true, $_fetchPreviews = false)
	{
		return ['header' => [0 => $this->sortedHeaders]];
	}

	function decode_subject($_string, $decode = true)
	{
		return $_string;
	}

	function getMessageHeader($_uid, $_partID = '', $decode = false, $preserveUnSeen = false, $_folder = '')
	{
		return $this->headerData;
	}

	function getMessageBody($_uid, $_htmlOptions = '', $_partID = null, ?\Horde_Mime_Part $_structure = null,
		$_preserveSeen = false, $_folder = '', &$calendar_part = null, ?bool $output_no_body = true)
	{
		return $this->bodyParts;
	}

	function getMessageAttachments($_uid, $_partID = null, ?\Horde_Mime_Part $_structure = null,
		$fetchEmbeddedImages = true, $fetchTextCalendar = false, $resolveTNEF = true, $_folder = '')
	{
		return $this->attachments;
	}

	function isSentFolder($_folderName, $_checkexistance = true, $_exactMatch = false)
	{
		return $this->sentFolder;
	}

	function flagMessages($_flag, $_messageUID, $_folder = null)
	{
		$this->flagCalls[] = [$_flag, $_messageUID, $_folder];
		return true;
	}
}

/**
 * tracker_mailhandler::check_mail()/process_message2()/is_automail2()/forward_message2()
 * previously had zero test coverage - the real IMAP fetch/parse/ticket-creation pipeline
 * that turns incoming mail into tracker tickets. process_message2()/is_automail2()/
 * forward_message2() already take the mail connection ($mailobject) as an explicit
 * parameter rather than creating it internally, so they can be exercised directly against
 * a PHPUnit mock of EGroupware\Api\Mail - no real IMAP server needed. Mail::get_mailcontent()
 * is a static method, but dispatches to $mailClass->getMessageHeader()/getMessageBody()/
 * getMessageAttachments()/isSentFolder() dynamically on whatever object is passed in, so
 * mocking those instance methods exercises the real content-assembly logic too.
 *
 * Deliberately does NOT create new accounts via admin_cmd_edit_user() (unlike
 * MailImportTest) - that call triggers a real AD-sync hook on this instance, which fails
 * without a reachable LDAP server, and is unrelated to what's under test here.
 */
class MailHandlerTest extends AppTest
{
	/** @var \tracker_mailhandler */
	protected $handler;

	protected function setUp() : void
	{
		parent::setUp();

		$this->handler = new \tracker_mailhandler([]);
	}

	private function mockMailbox() : Mail
	{
		return $this->getMockBuilder(Mail::class)
			->disableOriginalConstructor()
			->getMock();
	}

	private function headerFlags(array $overrides = []) : array
	{
		return array_merge([
			'subject'  => 'Trouble with the widget',
			'deleted'  => false,
			'seen'     => false,
			'recent'   => true,
			'answered' => false,
			'draft'    => false,
			'date'     => '2026-08-01 12:00:00',
		], $overrides);
	}

	// --- process_message2(): the flag guard at the top must skip without touching content ---

	public function testProcessMessageSkipsSeenMessage()
	{
		$mailobject = $this->mockMailbox();
		$mailobject->method('getHeaders')->willReturn(['header' => [0 => $this->headerFlags(['seen' => true])]]);
		$mailobject->method('decode_subject')->willReturnArgument(0);
		$mailobject->expects($this->never())->method('flagMessages');
		$mailobject->expects($this->never())->method('getMessageBody');

		$this->handler->mailhandling[0] = [];
		$this->assertFalse($this->handler->process_message2($mailobject, 42, 'INBOX', 0));
	}

	public function testProcessMessageSkipsDeletedMessage()
	{
		$mailobject = $this->mockMailbox();
		$mailobject->method('getHeaders')->willReturn(['header' => [0 => $this->headerFlags(['deleted' => true])]]);
		$mailobject->method('decode_subject')->willReturnArgument(0);
		$mailobject->expects($this->never())->method('flagMessages');

		$this->handler->mailhandling[0] = [];
		$this->assertFalse($this->handler->process_message2($mailobject, 42, 'INBOX', 0));
	}

	public function testProcessMessageSkipsDraftMessage()
	{
		$mailobject = $this->mockMailbox();
		$mailobject->method('getHeaders')->willReturn(['header' => [0 => $this->headerFlags(['draft' => true])]]);
		$mailobject->method('decode_subject')->willReturnArgument(0);
		$mailobject->expects($this->never())->method('flagMessages');

		$this->handler->mailhandling[0] = [];
		$this->assertFalse($this->handler->process_message2($mailobject, 42, 'INBOX', 0));
	}

	/**
	 * An unseen message from a sender that matches no known account/contact exercises the
	 * full content-fetch pipeline (Mail::get_mailcontent()'s static dispatch onto the mocked
	 * getMessageHeader()/getMessageBody()/getMessageAttachments()/isSentFolder(), then
	 * prepare_import_mail()) without needing a real, persisted ticket - 'ignore' mode means
	 * no ticket gets created, but everything up to that decision must run correctly first.
	 */
	public function testProcessMessageIgnoresUnrecognizedSender()
	{
		$subject = 'Trouble with the widget - unit test ' . uniqid();
		$mailobject = new MailHandlerTestDouble();
		$mailobject->sortedHeaders = $this->headerFlags(['subject' => $subject]);
		$mailobject->headerData = [
			'FROM'    => 'Nobody Known <definitely-not-a-known-sender@example.invalid>',
			'SUBJECT' => $subject,
		];
		$mailobject->bodyParts = [
			['mimeType' => 'text/plain', 'charSet' => 'utf-8', 'body' => 'The widget stopped spinning.'],
		];

		$this->handler->mailhandling[0] = [
			'default_tracker'    => 1,
			'unrecognized_mails' => 'ignore',
		];

		$this->assertFalse($this->handler->process_message2($mailobject, 42, 'INBOX', 0));
		// flagged as seen before the unrecognized-sender check runs, regardless of outcome
		$this->assertSame([['seen', 42, 'INBOX']], $mailobject->flagCalls);
	}

	// --- is_automail2(): bounce/autoreply detection (previously completely non-functional -
	// see the commit message for the $msgHeader/$msgHeaders and $_folderName bugs this fixed) ---

	public function testIsAutomail2DetectsBounceAndDeletes()
	{
		$mailobject = $this->mockMailbox();
		$mailobject->expects($this->once())->method('deleteMessages')->with(99, 'INBOX', 'move_to_trash');

		$this->handler->mailhandling[0]['bounces'] = 'delete';
		$headers = ['FROM' => 'Mailer-Daemon@example.com'];

		$this->assertTrue($this->handler->is_automail2($mailobject, 99, 'Undeliverable', $headers, 0, 'INBOX'));
	}

	public function testIsAutomail2DetectsBounceIgnoreModeDoesNotDelete()
	{
		$mailobject = $this->mockMailbox();
		$mailobject->expects($this->never())->method('deleteMessages');

		$this->handler->mailhandling[0]['bounces'] = 'ignore';
		$headers = ['FROM' => 'Mailer-Daemon@example.com'];

		$this->assertTrue($this->handler->is_automail2($mailobject, 99, 'Undeliverable', $headers, 0, 'INBOX'));
	}

	public function testIsAutomail2DetectsAutoreplyDeleteMode()
	{
		$mailobject = $this->mockMailbox();
		$mailobject->expects($this->once())->method('deleteMessages')->with(100, 'INBOX', 'move_to_trash');

		$this->handler->mailhandling[0]['autoreplies'] = 'delete';
		$headers = ['FROM' => 'someone@example.com', 'SUBJECT' => 'Out of Office'];

		$this->assertTrue($this->handler->is_automail2($mailobject, 100, 'Out of Office', $headers, 0, 'INBOX'));
	}

	/**
	 * 'process' mode for autoreplies means "treat as a normal message" - is_automail2()
	 * returns false so process_message2() continues as if it were not an automail.
	 */
	public function testIsAutomail2DetectsAutoreplyProcessMode()
	{
		$mailobject = $this->mockMailbox();
		$mailobject->expects($this->never())->method('deleteMessages');

		$this->handler->mailhandling[0]['autoreplies'] = 'process';
		$headers = ['FROM' => 'someone@example.com', 'SUBJECT' => 'Autoreply: got your message'];

		$this->assertFalse($this->handler->is_automail2($mailobject, 101, 'Autoreply: got your message', $headers, 0, 'INBOX'));
	}

	public function testIsAutomail2OrdinaryMessageIsNotAutomail()
	{
		$mailobject = $this->mockMailbox();
		$mailobject->expects($this->never())->method('deleteMessages');

		$headers = ['FROM' => 'colleague@example.com'];

		$this->assertFalse((bool)$this->handler->is_automail2($mailobject, 102, 'Trouble with the widget', $headers, 0, 'INBOX'));
	}

	// --- forward_message2() ---

	public function testForwardMessage2Success()
	{
		$mailobject = $this->mockMailbox();
		$mailobject->expects($this->once())->method('getMessageRawBody')
			->with(55, '', 'INBOX')
			->willReturn("From: someone@example.com\r\nSubject: test\r\n\r\nbody");

		$smtpMail = $this->getMockBuilder(Api\Mailer::class)->disableOriginalConstructor()->getMock();
		$smtpMail->expects($this->once())->method('addStringAttachment')
			->with($this->stringContains('Subject: test'), 'Original subject', 'message/rfc822');
		$smtpMail->method('send')->willReturn(true);

		$this->handler->smtpMail = $smtpMail;
		$this->handler->mailhandling[0] = ['forward_to' => 'forward-target@example.com', 'folder' => 'INBOX'];

		$this->assertTrue($this->handler->forward_message2($mailobject, 55, 'Original subject', 'unrecognized sender', 0));
	}

	public function testForwardMessage2FailureReturnsFalse()
	{
		$mailobject = $this->mockMailbox();
		$mailobject->method('getMessageRawBody')->willReturn('raw bytes');

		$smtpMail = $this->getMockBuilder(Api\Mailer::class)->disableOriginalConstructor()->getMock();
		$smtpMail->method('send')->willReturn(false);

		$this->handler->smtpMail = $smtpMail;
		$this->handler->mailhandling[0] = ['forward_to' => 'forward-target@example.com', 'folder' => 'INBOX'];

		$this->assertFalse($this->handler->forward_message2($mailobject, 56, 'Original subject', 'unrecognized sender', 0));
	}
}
