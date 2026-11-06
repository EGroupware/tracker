<?php
/**
 * EGroupware Tracker - inline (cid:) image handling for mail converted to tickets via the
 * manual "Save mail as Ticket" path
 *
 * @link http://www.egroupware.org
 * @package tracker
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Tracker;

require_once realpath(__DIR__.'/../../api/tests/AppTest.php');
require_once realpath(__DIR__.'/TrackerUiEditSaveOnlyTestDouble.php');

use EGroupware\Api;
use EGroupware\Api\AppTest;

/**
 * Regression coverage for ticket #126501's OTHER mail-to-ticket path: the manual "Save mail as
 * Ticket" context-menu action (mail_integration::get_integrate_data()/integrate() + the
 * tracker_ui::mail_import()/edit() save flow), as opposed to MailToTicketInlineImageTest's
 * automatic cron mail-handler coverage.
 *
 * mail_integration::get_integrate_data()'s own IMAP-fetching half (the "already saved mail"
 * branch, which opens a real mailbox via Mail::getInstance()) is NOT exercised here - that part
 * was never the buggy part and genuinely needs a live mail account (see
 * [[project_mail_test_accounts]]). What ac­tually changed in the Nov-2026 fix
 * (mail_integration::fix_inline_images(), tracker_bo::prepare_import_mail(),
 * tracker_ui.inc.php's save-handler wiring) is downstream of that fetch, so this builds the
 * $mailContent array get_integrate_data() WOULD have produced by hand - same INLINE_PREFIX
 * placeholder shape (self::INLINE_PREFIX.$egw_data.'" title="['.$name.']'), same
 * Link::set_data()-backed 'egw_data' staging - and feeds it into the real
 * prepare_import_mail()/tracker_ui::edit() save flow from there on.
 *
 * tracker_ui::edit()'s "submitted form" branch falls through from case 'save' into case 'cancel',
 * which calls Framework::window_close();exit() when $content['popup'] is true - saveViaEdit()
 * always passes popup=false (and no own_referer) to land safely past that into the
 * $this->index(...) tail instead of terminating the test process.
 */
class MailImportInlineImageTest extends AppTest
{
	/** @var \tracker_bo */
	protected $bo;

	/** @var \tracker_ui */
	protected $ui;

	/** @var int a real, accessible tracker/queue id, see MailToTicketInlineImageTest's own property */
	protected $trackerId;

	/** @var int[] tr_ids created by a test, removed again in tearDown() */
	protected $createdTicketIds = [];

	protected function setUp() : void
	{
		parent::setUp();

		$this->bo = new \tracker_bo();
		$this->ui = new \TrackerUiEditSaveOnlyTestDouble();
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

	/**
	 * Stand-in for the real Link::set_data() call mail_integration::get_integrate_data() makes
	 * (which calls Api\Mail::getAttachmentAccount() against a live IMAP connection) - this one
	 * just base64_decode()s the bytes straight back, no mail account needed, but is resolved by
	 * Link::link()/Link::get_data() through the exact same mechanism.
	 */
	private function fakeEgwData(string $bytes) : string
	{
		return Api\Link::set_data('image/png', 'base64_decode', [base64_encode($bytes)], true);
	}

	/**
	 * One inline (cid, now already resolved to the INLINE_PREFIX placeholder by
	 * mail_integration::get_integrate_data()/integrate()) image plus two plain external images -
	 * same shape as MailToTicketInlineImageTest::htmlBody(), see its docblock for why both an
	 * https and a plain http one are included.
	 */
	private function htmlBody(string $egw_data, string $name) : string
	{
		return '<p>Look at this:</p>'.
			'<img src="'.\mail_integration::INLINE_PREFIX.$egw_data.'" title="['.$name.']">'.
			'<img src="https://example.invalid/external-tracking.png">'.
			'<img src="http://example.invalid/plain-http-image.png">';
	}

	/**
	 * Mimics clicking "Save" on the prefilled ticket-edit popup tracker_ui::mail_import() would
	 * have opened - see this class's own docblock for why popup/own_referer must be set this way.
	 */
	private function saveViaEdit(array $ticket)
	{
		$content = $ticket;
		$content['button'] = ['save' => 1];
		$content['popup'] = false;
		$content['own_referer'] = null;

		return $this->ui->edit($content);
	}

	// --- Brand-new ticket via manual "Save mail as Ticket" (scenarios 1+2+3) ---

	public function testManualSaveCreatesNewHtmlTicketResolvesInlineCidImageAndKeepsExternalImage()
	{
		$this->ui->htmledit = true;
		$imageBytes = 'FAKE-IMAGE-BYTES-manual-new';
		$egw_data = $this->fakeEgwData($imageBytes);

		$addresses = [['email' => 'reporter-manual-1@example.invalid', 'name' => 'Reporter']];
		$subject = 'A brand-new manual problem - unit test '.uniqid();
		$html = $this->htmlBody($egw_data, 'screenshot.png');
		$attachments = [
			[
				'name'     => 'screenshot.png',
				'type'     => 'image/png',
				'mimeType' => 'image/png',
				'size'     => strlen($imageBytes),
				'egw_data' => $egw_data,
			],
		];

		// same call tracker_ui::mail_import() makes with mail_integration::get_integrate_data()'s
		// result - entry_id=null means "no existing ticket", same as get_integrate_data() returns
		// for mail that was never addressed to an existing ticket
		$ticket = $this->ui->prepare_import_mail($addresses, $subject, $html, $attachments, null);
		$ticket['tr_tracker'] = $this->trackerId;
		$ticket['tr_edit_mode'] = 'html';

		$this->saveViaEdit($ticket);

		$tr_id = $this->ui->data['tr_id'];
		$this->assertNotEmpty($tr_id, 'A new ticket should have been created');
		$this->createdTicketIds[] = $tr_id;

		$ticketRow = $this->bo->read($tr_id);
		$description = $ticketRow['tr_description'];

		$this->assertStringNotContainsString(\mail_integration::INLINE_PREFIX, $description,
			'The inline image must no longer reference the unresolved mail_integration placeholder');
		$this->assertStringContainsString('https://example.invalid/external-tracking.png', $description,
			'An external (non-cid) image reference must pass through completely unchanged');
		$this->assertStringNotContainsString('http://example.invalid/plain-http-image.png', $description,
			'A plain http: external image must be rewritten, not left as-is, to avoid mixed-content '.
			'once the ticket is viewed over https (ticket #126501 follow-up)');
		$this->assertStringContainsString(
			'src="'.\EGroupware\Mail\Ui::image_proxy().'example.invalid/plain-http-image.png"', $description,
			'The http: image must be rewritten via Ui::image_proxy()');

		$vfsPath = "/apps/tracker/$tr_id/screenshot.png";
		$this->assertTrue(Api\Vfs::file_exists($vfsPath), "Expected the attachment at $vfsPath to exist");
		$this->assertSame($imageBytes, file_get_contents(Api\Vfs::PREFIX.$vfsPath),
			'The VFS file must contain the actual image bytes from the mail attachment');
	}

	// --- Reply/comment added to an EXISTING ticket via manual "Save mail as Ticket" (scenarios 1+2+4) ---

	public function testManualSaveAddsHtmlReplyToExistingTicketResolvesInlineCidImageAndKeepsExternalImage()
	{
		$this->ui->htmledit = true;

		$this->bo->data = [
			'tr_summary'     => 'Existing ticket for manual reply test '.uniqid(),
			'tr_tracker'     => $this->trackerId,
			'tr_status'      => \tracker_bo::STATUS_OPEN,
			'tr_creator'     => $GLOBALS['egw_info']['user']['account_id'],
			'tr_description' => 'Initial description, no images.',
			'tr_edit_mode'   => 'html',
		];
		$this->bo->save();
		$tr_id = $this->bo->data['tr_id'];
		$this->createdTicketIds[] = $tr_id;

		$imageBytes = 'FAKE-IMAGE-BYTES-manual-reply';
		$egw_data = $this->fakeEgwData($imageBytes);

		$addresses = [['email' => 'reporter-manual-2@example.invalid', 'name' => 'Reporter']];
		$subject = 'Re: '.$this->bo->data['tr_summary'];
		$html = $this->htmlBody($egw_data, 'screenshot2.png');
		$attachments = [
			[
				'name'     => 'screenshot2.png',
				'type'     => 'image/png',
				'mimeType' => 'image/png',
				'size'     => strlen($imageBytes),
				'egw_data' => $egw_data,
			],
		];

		// entry_id=$tr_id: mail_import() passes $mailContent['entry_id'] through unchanged - for an
		// existing ticket get_integrate_data() resolves that from the mail's own row id, here just
		// given directly since we are not going through a real mailbox fetch
		$ticket = $this->ui->prepare_import_mail($addresses, $subject, $html, $attachments, $tr_id);

		$this->saveViaEdit($ticket);

		$this->bo->read($tr_id, '', '', null, true);
		$replies = $this->bo->data['replies'];
		$this->assertNotEmpty($replies, 'A reply/comment should have been added to the ticket');
		$reply = $replies[0];
		$reply_message = $reply['reply_message'];

		$this->assertStringNotContainsString(\mail_integration::INLINE_PREFIX, $reply_message,
			'The inline image must no longer reference the unresolved mail_integration placeholder '.
			'(this is exactly the bug fixed in commit e54cafc00b - a reply to an EXISTING ticket never '.
			'got this placeholder resolved at all, since its attachment is linked immediately, not staged)');
		$this->assertStringContainsString('https://example.invalid/external-tracking.png', $reply_message,
			'An external (non-cid) image reference must pass through completely unchanged');
		$this->assertStringNotContainsString('http://example.invalid/plain-http-image.png', $reply_message,
			'A plain http: external image must be rewritten, not left as-is');
		$this->assertStringContainsString(
			'src="'.\EGroupware\Mail\Ui::image_proxy().'example.invalid/plain-http-image.png"', $reply_message,
			'The http: image must be rewritten via Ui::image_proxy()');

		$vfsPath = "/apps/tracker/$tr_id/comments/".$reply['reply_id'].'/screenshot2.png';
		$this->assertTrue(Api\Vfs::file_exists($vfsPath), "Expected the attachment at $vfsPath to exist");
		$this->assertSame($imageBytes, file_get_contents(Api\Vfs::PREFIX.$vfsPath),
			'The VFS file must contain the actual image bytes from the mail attachment');
	}
}
