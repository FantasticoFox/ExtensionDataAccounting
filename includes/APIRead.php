<?php

namespace MediaWiki\Extension\Example;

use MediaWiki\Rest\SimpleHandler;
use Wikimedia\ParamValidator\ParamValidator;
use MediaWiki\MediaWikiServices;

use Title;
use WikitextContent;
use WikiPage;

# include / exclude for debugging
error_reporting(E_ALL);
ini_set("display_errors", 1);

require_once("ApiUtil.php");
require_once("Util.php");

function selectToArray($db, $table, $col, $conds) {
    $out = array();
    $res = $db->select(
        $table,
        [$col],
        $conds,
    );
    foreach ($res as $row) {
        array_push($out, $row->{$col});
    }
    return $out;
}

// TODO move to Util.php
function updateDomainManifest($witness_event_id, $db) {
    $row = $db->selectRow(
        'witness_events',
        [
            "domain_id",
            "domain_manifest_title",
            "domain_manifest_verification_hash",
            "merkle_root",
            "witness_event_verification_hash",
            "witness_network",
            "smart_contract_address",
            "witness_event_transaction_hash",
            "sender_account_address",
        ],
        [ 'witness_event_id' => $witness_event_id ]
    );
    if (!$row) {
        return;
    }
    $dm = "Domain Manifest $witness_event_id";
    if ( ('Data Accounting:' . $dm) !== $row->domain_manifest_title) {
        return;
    }
    //6942 is custom namespace. See namespace definition in extension.json.
    $title = Title::newFromText( $dm, 6942 );
    $page = new WikiPage( $title );
    $text = "\n<h1> Witness Event Publishing Data </h1>\n";
    $text .= "<p> This means, that the Witness Event Verification Hash has been written to a Witness Network and has been Timestamped.\n";

    $text .= "* Witness Event: " . $witness_event_id . "\n";
    $text .= "* Domain ID: " . $row->domain_id . "\n";
    $text .= "* Domain Manifest Title: " . $row->domain_manifest_title . "\n";
    // We don't include witness hash.
    $text .= "* Page Domain Manifest verification Hash: " . $row->domain_manifest_verification_hash . "\n";
    $text .= "* Merkle Root: " . $row->merkle_root . "\n";
    $text .= "* Witness Event Verification Hash: " . $row->witness_event_verification_hash . "\n";
    $text .= "* Witness Network: " . $row->witness_network . "\n";
    $text .= "* Smart Contract Address: " . $row->smart_contract_address . "\n";
    $text .= "* Transaction Hash: " . $row->witness_event_transaction_hash . "\n";
    $text .= "* Sender Account Address: " . $row->sender_account_address . "\n";
    // We don't include source.

    $pageText = $page->getContent()->getText();
    // We create a new content using the old content, and append $text to it.
    $newContent = new WikitextContent($pageText . $text);
    $page->doEditContent( $newContent,
        "Domain Manifest witnessed" );
}

/**
 * Extension:DataAccounting Standard Rest API
 */
class APIRead extends SimpleHandler {

    private const VALID_ACTIONS = [ 
        'verify_page', //READ
        'get_page_by_rev_id', //READ
        'page_all_rev', //READ
        'get_page_last_rev', //READ
        'get_witness_data',  //READ
        'request_merkle_proof', //READ
        'request_hash'  //READ
    ];

    /** @inheritDoc */
    public function run( $action ) {
        $params = $this->getValidatedParams();
        $var1 = $params['var1'];
        $var2 = $params['var2'] ?? null;
        $var3 = $params['var3'] ?? null;
        $var4 = $params['var4'] ?? null;
        switch ( $action ) {
            #Expects rev_id as input and returns verification_hash(required), signature(optional), public_key(optional), wallet_address(optional), witness_id(optional)
                case 'verify_page':

            $rev_id = $var1;

            $lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
            $dbr = $lb->getConnectionRef( DB_REPLICA );
            $row = $dbr->selectRow(
                'page_verification', 
                [
                    'rev_id',
                    'domain_id',
                    'hash_verification',
                    'time_stamp',
                    'signature',
                    'public_key',
                    'wallet_address',
                    'witness_event_id'
                ],
                ['rev_id' => $var1],
                __METHOD__
            );

            if (!$row) {
                return [];
            }

            $output = [
                'rev_id' => $rev_id,
                'domain_id' => $row->domain_id,
                'verification_hash' => $row->hash_verification,
                'time_stamp' => $row->time_stamp,
                'signature' => $row->signature,
                'public_key' => $row->public_key,
                'wallet_address' => $row->wallet_address,
                'witness_event_id' => $row->witness_event_id,
            ];
            return $output;

            #Expects Revision_ID as input and returns page_title and page_id
        case 'get_page_by_rev_id':
            /** Database Query */ 
            $lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
            $dbr = $lb->getConnectionRef( DB_REPLICA );
            $res = $dbr->select(
                'page_verification',
                [ 'rev_id','page_title','page_id' ],
                'rev_id = '.$var1,
                __METHOD__
            );

            $output = '';
            foreach( $res as $row ) {
                $output = 'Page Title: ' . $row->page_title .' Page_ID: ' . $row->page_id;  
            }
            return $output;

            #Expects Page Title and returns LAST verified revision
            #select * from page_verification where page_title = 'Witness' ORDER BY rev_id DESC LIMIT 1;
            #POTENTIALLY USELESS AS ALL PAGES GET VERIFIED?  
        case 'get_page_last_rev':
            $page_title = $var1;
            /** Database Query */
            $lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
            $dbr = $lb->getConnectionRef( DB_REPLICA );
            // TODO use max(rev_id) instead
            $res = $dbr->select(
                'page_verification',
                [ 'rev_id', 'page_title', 'page_id' ],
                [ 'page_title' => $page_title ],
                __METHOD__,
                [ 'ORDER BY' => 'rev_id' ] 
            );

            $output = json_decode("{}");
            foreach( $res as $row ) {
                $output = [
                    'page_title' => $row->page_title,
                    'page_id' => $row->page_id,
                    'rev_id' => $row->rev_id,
                ];
            }
            return $output;

            #Expects Page Title and returns ALL verified revisions
            #NOT IMPLEMENTED
        case 'page_all_rev':
            $page_title = $var1;
            return get_page_all_rev($page_title);

            #request_merkle_proof:expects witness_id and page_verification hash and returns left_leaf,righ_leaf and successor hash to verify the merkle proof node by node, data is retrieved from the witness_merkle_tree db. Note: in some cases there will be multiple replays to this query. In this case it is required to use the depth as a selector to go through the different layers. Depth can be specified via the $depth parameter; 
        case 'request_merkle_proof':
            if ($var1 == null) {
                return "var1 (/witness_event_id) is not specified but expected";                
            }
            if ($var2 === null) {
                return "var2 (page_verification_hash) is not specified but expected";
            }
            //Redeclaration
            $witness_event_id = $var1;
            $page_verification_hash = $var2;
            $depth = $var3;
            $output = requestMerkleProof($witness_event_id, $page_verification_hash, $depth);
            return [json_encode($output)];

            #Expects 'get_witness_data\'- USES witness_event_id - used to retrieve all required data to execute a witness event (including domain_manifest_verification_hash, merkle_root, network ID or name, witness smart contract address, transaction_id) for the publishing via Metamask'];
        case 'get_witness_data':
            if ($var1 === null) {
                return "var1 (witness_event_id) is not specified but expected";
            }
            $witness_event_id = $var1;
            $output = getWitnessData($witness_event_id);
                                 
            return $output;

            #Expects Revision_ID [Required] Signature[Required], Public Key[Required] and Wallet Address[Required] as inputs; Returns a status for success or failure
               case 'request_hash':
            $rev_id = $var1;
            $lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
            $dbr = $lb->getConnectionRef( DB_REPLICA );

            $res = $dbr->select(
            'page_verification',
            [ 'rev_id','hash_verification' ],
                'rev_id = ' . $rev_id,
            __METHOD__
            );

            $output = '';
            foreach( $res as $row ) {
                $output .= 'I sign the following page verification_hash: [0x' . $row->hash_verification .']';
            }
            return $output;

        default:
            //TODO Return correct error code https://www.mediawiki.org/wiki/API:REST_API/Reference#PHP_3
            return 'ERROR: Invalid action';
        }
    }

    /** @inheritDoc */
    public function needsWriteAccess() {
        return false;
    }

    /** @inheritDoc */
    public function getParamSettings() {
        return [
            'action' => [
                self::PARAM_SOURCE => 'path',
                ParamValidator::PARAM_TYPE => self::VALID_ACTIONS,
                ParamValidator::PARAM_REQUIRED => true,
            ],
            'var1' => [
                self::PARAM_SOURCE => 'query',
                ParamValidator::PARAM_TYPE => 'string',
                ParamValidator::PARAM_REQUIRED => true,
            ],
            'var2' => [
                self::PARAM_SOURCE => 'query',
                ParamValidator::PARAM_TYPE => 'string',
                ParamValidator::PARAM_REQUIRED => false,
            ],
            'var3' => [
                self::PARAM_SOURCE => 'query',
                ParamValidator::PARAM_TYPE => 'string',
                ParamValidator::PARAM_REQUIRED => false,
            ],
            'var4' => [
                self::PARAM_SOURCE => 'query',
                ParamValidator::PARAM_TYPE => 'string',
                ParamValidator::PARAM_REQUIRED => false,
            ],
        ];
    }
}

