<?php
/**
 * HelloWorld Special page.
 *
 * @file
 */

namespace MediaWiki\Extension\Example;

use MediaWiki\MediaWikiServices;
use HTMLForm;

use MediaWiki\Extension\Example\FixedSizeTree;

require 'vendor/autoload.php';

# include / exclude for debugging
error_reporting(E_ALL);
ini_set("display_errors", 1);

class SpecialWitness extends \SpecialPage {

	/**
	 * Initialize the special page.
	 */
	public function __construct() {
		// A special page should at least have a name.
		// We do this by calling the parent class (the SpecialPage class)
		// constructor method with the name as first and only parameter.
		parent::__construct( 'Witness' );
	}

	/**
	 * Shows the page to the user.
	 * @param string $sub The subpage string argument (if any).
	 *  [[Special:SignMessage/subpage]].
	 */
	public function execute( $sub ) {
		$this->setHeaders();

		$formDescriptor = [
			'pagetitle' => [
				'label' => 'Page Title', // Label of the field
				'class' => 'HTMLTextField', // Input type
			]
		];
		$htmlForm = new HTMLForm( $formDescriptor, $this->getContext(), 'generatePageManifest' );
		$htmlForm->setSubmitText( 'Generate Page Manifest' );
		$htmlForm->setSubmitCallback( [ $this, 'generatePageManifest' ] );
		$htmlForm->show();

		$out = $this->getOutput();
		$out->setPageTitle( 'Witness' );
	}
   

	public static function generatePageManifest( $formData ) {
		$lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
		$dbr = $lb->getConnectionRef( DB_REPLICA );
		$res = $dbr->select(
			'page_verification',
			[ 'MAX(rev_id) as rev_id', 'page_title', 'hash_verification' ],
			'',
			__METHOD__,
			[ 'GROUP BY' => 'page_title']
		);

        function getHashSum($inputStr) {
            return hash("sha3-512", $inputStr);
        }

        $int = 0;
		$output = '';
		$verification_hashes = [];
		foreach( $res as $row ) {
			//$output .= 'Page Title: ' . $row->page_title . ' Revision_ID: ' . $row->rev_id . ' hash: ' . $row->hash_verification . 'counter: ' .  $int . 'array: ' . $myarray[$int] . "<br>";

			//$multiarray[$int] = 'Index: ' . $int . '<br> Page Title: ' . $row->page_title . '<br> Revision_ID: ' . $row->rev_id . '<br> Verification_Hash: ' . $row->hash_verification . "<br><br>";
            $titlearray[$int] =  $row->page_title;
            $verification_hashes[$int] =  $row->hash_verification;

            $output .= 'Index: ' . $int . '<br> Title: ' . $titlearray[$int] . '<br> Verification_Hash: ' . $verification_hashes[$int] . '<br><br>';
            $int == $int++;

		}

		$hasher = function ($data) {
			return hash('sha3-512', $data, false);
		};

		$tree = new FixedSizeTree(count($verification_hashes), $hasher, NULL, true);
        for ($i = 0; $i < count($verification_hashes); $i++) {
			$tree->set($i, $verification_hashes[$i]);
		}

		$out = $this->getOutput();
		$out->addHTML($output);// . '<br> Verification_Hash 1: ' . $verification_hashes[0] . '<br> Verification_Hash 2:' . $verification_hashes[1]);
		$out->addHTML('<br>' . json_encode($tree->getLayersAsObject(), JSON_PRETTY_PRINT));
		//$out->addHTML($output . '<br> Verification_Hash 1: ' . $verification_hashes[0] . '<br> Verification_Hash 2:' . $verification_hashes[1] . 'Verification_Hash 1 + 2: ' . getHashSum($verification_hashes[0].$verification_hashes[1]));
        return true;
	}

	/** @inheritDoc */
	protected function getGroupName() {
		return 'other';
	}
}
