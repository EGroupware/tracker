<?php
/**
 * EGroupware Tracker - inline (cid:) image handling for mail converted to tickets via the
 * automatic cron mail-handler
 *
 * @link http://www.egroupware.org
 * @package tracker
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Tracker;

require_once realpath(__DIR__.'/MailHandlerTest.php');    // MailHandlerTestDouble
require_once realpath(__DIR__.'/../../api/tests/AppTest.php');

use EGroupware\Api;
use EGroupware\Api\AppTest;

/**
 * MailHandlerTestDouble (see MailHandlerTest.php) covers getMessageHeader()/getMessageBody(), but
 * Mail::get_mailcontent() additionally calls $mailClass->getAttachment() once per non-eml
 * attachment to fetch its raw bytes (api/src/Mail.php ~7233), which the base double does not
 * implement (none of MailHandlerTest's existing cases use attachments). Keyed by partID, since
 * that is what get_mailcontent() passes through unchanged from the $attachments fixture below.
 */
class MailToTicketAttachmentTestDouble extends MailHandlerTestDouble
{
	public array $attachmentContentByPartId = [];

	function getAttachment($_uid, $_partID, $_winmail_nr=0, $_returnPart=true, $_stream=false, $_folder=null)
	{
		return ['attachment' => $this->attachmentContentByPartId[$_partID] ?? ''];
	}
}

/**
 * Regression coverage for ticket #126501: an inline (cid:) image in an incoming HTML mail must end
 * up as a real VFS attachment under /apps/tracker/$tr_id/ (or .../comments/$reply_id/ for a reply),
 * with the <img src> rewritten to point at it - not left as a raw "cid:..." reference nor an
 * unresolved "mail-<hash>" mail_integration placeholder. A plain (non-cid) external image
 * reference must pass through completely unchanged (no "block external images" privacy gate
 * applies here - once a mail has been deliberately turned into a ticket, the images are wanted,
 * see doc/ai/projects - that gate only ever applied to Mail::getdisplayableBody()'s discarded-HTML
 * plain-text conversion anyway, confirmed live: Api\Html\HtmLawed::purify() leaves a plain
 * '<img src="https://...">' untouched).
 *
 * Covers only the AUTOMATIC cron mail-handling path (tracker_mailhandler::process_message2(), the
 * "mail handling"/IMAP-polling-for-a-queue feature) for both a brand-new ticket and a reply/comment
 * added to an existing one - NOT the separate manual "Save mail as Ticket" path
 * (mail_integration::get_integrate_data() + tracker_ui::edit()), which has its own, different
 * placeholder mechanism and is not exercised here.
 */
class MailToTicketInlineImageTest extends AppTest
{
	/** @var \tracker_bo */
	protected $bo;

	/** @var \tracker_mailhandler */
	protected $handler;

	/** @var int[] tr_ids created by a test, removed again in tearDown() */
	protected $createdTicketIds = [];

	/** @var int a real, accessible tracker/queue id - tracker_so::search()'s queue ACL filtering
	 * returns nothing (not even for the creator) for a ticket whose tr_tracker the current test
	 * user has no read access to, which a freshly so::save()'d ticket defaults tr_tracker=0 for */
	protected $trackerId;

	protected function setUp() : void
	{
		parent::setUp();

		$this->bo = new \tracker_bo();
		$this->handler = new \tracker_mailhandler([]);
		$this->trackerId = array_key_first($this->bo->trackers) ?: 0;
	}

	protected function tearDown() : void
	{
		foreach ($this->createdTicketIds as $tr_id)
		{
			try
			{
				$this->bo->delete($tr_id);
			}
			catch (\Throwable $e)
			{
				// best effort only - do not fail the test over cleanup
			}
		}
		$this->createdTicketIds = [];

		parent::tearDown();
	}

	private function headerFlags(array $overrides=[]) : array
	{
		return array_merge([
			'subject'  => 'Test',
			'deleted'  => false,
			'seen'     => false,
			'recent'   => true,
			'answered' => false,
			'draft'    => false,
			'date'     => '2026-11-06 12:00:00',
		], $overrides);
	}

	/**
	 * One inline (cid) image plus two plain external images (one https, one plain http) - the
	 * https one is the shape Birgit's report (ticket #126501) describes; the plain http one checks
	 * that prepare_import_mail() rewrites it via Mail\Ui::image_proxy() - the same config mail's own
	 * live message preview uses (mail/templates/default/config.xet, mail/js/jmap.ts) - instead of
	 * leaving a raw http: URL that would hit the viewing browser's mixed-content handling.
	 */
	private function htmlBody(string $cid) : string
	{
		return '<p>Look at this:</p>'.
			'<img src="cid:'.$cid.'">'.
			'<img src="https://example.invalid/external-tracking.png">'.
			'<img src="http://example.invalid/plain-http-image.png">';
	}

	// --- Brand-new ticket created from an HTML mail with an inline CID image (scenarios 1+2+3) ---

	public function testCronCreatesNewHtmlTicketResolvesInlineCidImageAndKeepsExternalImage()
	{
		$subject = 'A brand-new problem - unit test '.uniqid();
		$cid = 'image1@unittest';
		$imageBytes = 'FAKE-IMAGE-BYTES-new-ticket';

		$mailobject = new MailToTicketAttachmentTestDouble();
		$mailobject->sortedHeaders = $this->headerFlags(['subject' => $subject]);
		$mailobject->headerData = [
			'FROM'    => 'Reporter <definitely-unknown-reporter-1@example.invalid>',
			'SUBJECT' => $subject,
		];
		$mailobject->bodyParts = [
			['mimeType' => 'text/html', 'charSet' => 'utf-8', 'body' => $this->htmlBody($cid)],
		];
		$mailobject->attachments = [
			[
				'name'     => 'screenshot.png',
				'mimeType' => 'image/png',
				'type'     => 'image/png',
				'size'     => strlen($imageBytes),
				'partID'   => '2',
				'cid'      => $cid,
			],
		];
		$mailobject->attachmentContentByPartId = ['2' => $imageBytes];

		// Bypass the "no matching contact found" unrecognized-sender branch (prepare_import_mail()
		// sets tr_creator=$this->user plus a "msg" when the sender's address matches no addressbook
		// contact, which process_message2() then treats as a potentially-unwanted sender) - we are
		// not testing creator-resolution here (see MailImportTest for that), just the inline-image
		// handling, so route it through the plain "default" / "unrec_mail" acceptance path.
		$this->handler->mailhandling[0] = [
			'default_tracker'    => $this->trackerId,
			'unrecognized_mails' => 'default',
			'unrec_mail'         => $GLOBALS['egw_info']['user']['account_id'],
		];
		// A brand-new ticket has no existing tr_edit_mode to read, so process_message2() never
		// touches $this->htmledit for it - pin it directly for a deterministic HTML-mode test.
		$this->handler->htmledit = true;

		$result = $this->handler->process_message2($mailobject, 1, 'INBOX', 0);

		$this->assertTrue($result, 'process_message2() should report success');

		// $this->handler->ticketId stays 0 for a brand-new ticket (set once, before save(), from
		// prepare_import_mail()'s pre-save tr_id==0) - the real id only ever lands in ->data['tr_id']
		$tr_id = $this->handler->data['tr_id'];
		$this->assertNotEmpty($tr_id, 'A new ticket should have been created');
		$this->createdTicketIds[] = $tr_id;

		$ticket = $this->bo->read($tr_id);
		$description = $ticket['tr_description'];

		$this->assertStringNotContainsString('cid:', $description,
			'The inline image must no longer reference the raw cid:');
		$this->assertStringNotContainsString(\mail_integration::INLINE_PREFIX, $description,
			'The inline image must not be left as an unresolved mail_integration placeholder');
		$this->assertStringContainsString('https://example.invalid/external-tracking.png', $description,
			'An external (non-cid) image reference must pass through completely unchanged');
		$this->assertStringNotContainsString('http://example.invalid/plain-http-image.png', $description,
			'A plain http: external image must be rewritten, not left as-is, to avoid mixed-content '.
			'once the ticket is viewed over https - prepare_import_mail() now applies the same '.
			'Ui::image_proxy() rewrite mail\'s own display does live (ticket #126501 follow-up)');
		$this->assertStringContainsString(
			'src="'.\EGroupware\Mail\Ui::image_proxy().'example.invalid/plain-http-image.png"', $description,
			'The http: image must be rewritten via Ui::image_proxy() (picking up a hosting\'s own '.
			'configured proxy, not just the bare https:// default)');

		// Link::vfs_path() returns a "stylite.links://" logical-symlink URL on this (EPL-enabled)
		// instance, which Vfs::file_exists()/file_get_contents() don't resolve the same way a plain
		// app-storage path does - use the real underlying path instead (same one Vfs::find() below
		// confirms the attachment actually lives at).
		$vfsPath = "/apps/tracker/$tr_id/screenshot.png";
		$this->assertTrue(Api\Vfs::file_exists($vfsPath), "Expected the attachment at $vfsPath to exist");
		$this->assertSame($imageBytes, file_get_contents(Api\Vfs::PREFIX.$vfsPath),
			'The VFS file must contain the actual image bytes from the mail attachment');
	}

	// --- Reply/comment added to an EXISTING HTML ticket via the cron handler (scenario 1+2+4) ---

	public function testCronAddsHtmlReplyToExistingTicketResolvesInlineCidImageAndKeepsExternalImage()
	{
		$this->bo->data = [
			'tr_summary'     => 'Existing ticket for reply test '.uniqid(),
			'tr_tracker'     => $this->trackerId,
			'tr_status'      => \tracker_bo::STATUS_OPEN,
			'tr_creator'     => $GLOBALS['egw_info']['user']['account_id'],
			'tr_description' => 'Initial description, no images.',
			'tr_edit_mode'   => 'html',
		];
		$this->bo->save();
		$tr_id = $this->bo->data['tr_id'];
		$this->createdTicketIds[] = $tr_id;
		$summary = $this->bo->data['tr_summary'];

		// get_ticketId() expects " #<id>[:|-] <summary>" somewhere in the (adapted) subject
		$subject = "Tracker #$tr_id: $summary";
		$cid = 'image2@unittest';
		$imageBytes = 'FAKE-IMAGE-BYTES-reply';

		$mailobject = new MailToTicketAttachmentTestDouble();
		$mailobject->sortedHeaders = $this->headerFlags(['subject' => $subject]);
		$mailobject->headerData = [
			'FROM'    => 'Reporter <definitely-unknown-reporter-2@example.invalid>',
			'SUBJECT' => $subject,
		];
		$mailobject->bodyParts = [
			['mimeType' => 'text/html', 'charSet' => 'utf-8', 'body' => $this->htmlBody($cid)],
		];
		$mailobject->attachments = [
			[
				'name'     => 'screenshot2.png',
				'mimeType' => 'image/png',
				'type'     => 'image/png',
				'size'     => strlen($imageBytes),
				'partID'   => '2',
				'cid'      => $cid,
			],
		];
		$mailobject->attachmentContentByPartId = ['2' => $imageBytes];

		// Same "no matching contact found" bypass as above, but for an EXISTING ticket: a reply
		// whose sender matches no contact (or no tracker-staff contact) also sets reply_creator ==
		// $this->user + "msg", tripping the same unrecognized-sender branch - unrec_reply=>0 falls
		// back to the ticket's own original tr_creator as the acting user, which is a normal,
		// already-authorized account, so save() proceeds normally.
		$this->handler->mailhandling[0] = [
			'unrecognized_mails' => 'default',
			'unrec_mail'         => $GLOBALS['egw_info']['user']['account_id'],
			'unrec_reply'        => 0,
		];

		$result = $this->handler->process_message2($mailobject, 2, 'INBOX', 0);

		$this->assertTrue($result, 'process_message2() should report success for a reply to an existing ticket');
		$this->assertEquals($tr_id, $this->handler->ticketId,
			'The mail should have been recognized as a reply to the existing ticket, not a new one');

		$this->bo->read($tr_id, '', '', null, true);
		$replies = $this->bo->data['replies'];
		$this->assertNotEmpty($replies, 'A reply/comment should have been added to the ticket');
		$reply = $replies[0];
		$reply_message = $reply['reply_message'];

		$this->assertStringNotContainsString('cid:', $reply_message,
			'The inline image must no longer reference the raw cid: (ticket #126501 regression: '.
			'this previously stayed broken for a reply to an EXISTING ticket specifically)');
		$this->assertStringNotContainsString(\mail_integration::INLINE_PREFIX, $reply_message,
			'The inline image must not be left as an unresolved mail_integration placeholder');
		$this->assertStringContainsString('https://example.invalid/external-tracking.png', $reply_message,
			'An external (non-cid) image reference must pass through completely unchanged');
		$this->assertStringNotContainsString('http://example.invalid/plain-http-image.png', $reply_message,
			'A plain http: external image must be rewritten, not left as-is - see the same assertion in '.
			'the new-ticket test above for why this matters');
		$this->assertStringContainsString(
			'src="'.\EGroupware\Mail\Ui::image_proxy().'example.invalid/plain-http-image.png"', $reply_message,
			'The http: image must be rewritten via Ui::image_proxy()');

		// see the new-ticket test above for why this is a plain path, not Link::vfs_path()
		$vfsPath = "/apps/tracker/$tr_id/comments/".$reply['reply_id'].'/screenshot2.png';
		$this->assertTrue(Api\Vfs::file_exists($vfsPath), "Expected the attachment at $vfsPath to exist");
		$this->assertSame($imageBytes, file_get_contents(Api\Vfs::PREFIX.$vfsPath),
			'The VFS file must contain the actual image bytes from the mail attachment');
	}

	// --- Reply to an EXISTING plain-text (ascii) ticket (scenario 5) ---

	/**
	 * Plain-text tickets/replies can't render an <img>, so the mail body's HTML->text conversion
	 * leaves a "[cid:...]" placeholder instead - prepare_import_mail() swaps that for the
	 * attachment's own name as a plain label ("[screenshot3.png]"), no link, since there is nothing
	 * to link to in plain text.
	 */
	public function testCronAddsPlainTextReplyToExistingTicketReplacesCidPlaceholderWithAttachmentName()
	{
		$this->bo->data = [
			'tr_summary'     => 'Existing ascii ticket for reply test '.uniqid(),
			'tr_tracker'     => $this->trackerId,
			'tr_status'      => \tracker_bo::STATUS_OPEN,
			'tr_creator'     => $GLOBALS['egw_info']['user']['account_id'],
			'tr_description' => 'Initial description, no images.',
			'tr_edit_mode'   => 'ascii',
		];
		$this->bo->save();
		$tr_id = $this->bo->data['tr_id'];
		$this->createdTicketIds[] = $tr_id;
		$summary = $this->bo->data['tr_summary'];

		$subject = "Tracker #$tr_id: $summary";
		$cid = 'image3@unittest';
		$imageBytes = 'FAKE-IMAGE-BYTES-plaintext-reply';

		$mailobject = new MailToTicketAttachmentTestDouble();
		$mailobject->sortedHeaders = $this->headerFlags(['subject' => $subject]);
		$mailobject->headerData = [
			'FROM'    => 'Reporter <definitely-unknown-reporter-3@example.invalid>',
			'SUBJECT' => $subject,
		];
		$mailobject->bodyParts = [
			['mimeType' => 'text/plain', 'charSet' => 'utf-8', 'body' => "See attached: [cid:$cid]"],
		];
		$mailobject->attachments = [
			[
				'name'     => 'screenshot3.png',
				'mimeType' => 'image/png',
				'type'     => 'image/png',
				'size'     => strlen($imageBytes),
				'partID'   => '2',
				'cid'      => $cid,
			],
		];
		$mailobject->attachmentContentByPartId = ['2' => $imageBytes];

		$this->handler->mailhandling[0] = [
			'unrecognized_mails' => 'default',
			'unrec_mail'         => $GLOBALS['egw_info']['user']['account_id'],
			'unrec_reply'        => 0,
		];

		$result = $this->handler->process_message2($mailobject, 3, 'INBOX', 0);

		$this->assertTrue($result, 'process_message2() should report success for a plain-text reply');
		$this->assertEquals($tr_id, $this->handler->ticketId);

		$this->bo->read($tr_id, '', '', null, true);
		$reply_message = $this->bo->data['replies'][0]['reply_message'];

		$this->assertStringContainsString('[screenshot3.png]', $reply_message,
			'The "[cid:...]" placeholder must be swapped for the attachment\'s own name');
		$this->assertStringNotContainsString('[cid:', $reply_message,
			'The raw "[cid:...]" placeholder must not survive into the saved reply');
	}
}
