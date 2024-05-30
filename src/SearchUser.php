<?php

namespace MediaWiki\Extension\GloopControl;

use ExtensionRegistry;
use FormatJson;
use Language;
use MediaWiki\Extension\OATHAuth\IModule;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\User\UserFactory;

class SearchUser extends GloopControlSubpage {

	private UserFactory $userFactory;

	private ExtensionRegistry $er;

	private Language $lang;

	function __construct( SpecialGloopControl $special ) {
		$this->userFactory = MediaWikiServices::getInstance()->getUserFactory();
		$this->er = ExtensionRegistry::getInstance();
		$this->lang = $special->getLanguage();
		parent::__construct( $special );
	}

	function execute() {
		$this->special->getOutput()->setPageTitle( $this->special->getOutput()->msg( 'gloopcontrol-user' )->text() );
		$this->displayForm();
	}

	private function displayForm() {
		// Build the form
		$desc = [
			'user' => [
				'type' => 'user',
				'cssclass' => 'mw-autocomplete-user',
				'label-message' => 'gloopcontrol-user-username',
				'exists' => true
			]
		];

		// Display the form
		$form = \HTMLForm::factory( 'ooui', $desc, $this->special->getContext() );
		$form
			->setSubmitCallback( [ $this, 'onFormSubmit' ] )
			->show();
	}

	public function onFormSubmit( $formData ) {
		$out = $this->special->getOutput();
		$name = $formData[ 'user' ];

		// Lookup the user's info
		$user = $this->userFactory->newFromName( $name );
		if ( $user === null || $user->getId() === 0 ) {
			// The form should already validate if a user exists or not - this is just here for redundancy.
			$out->addHTML(Html::errorBox(
				$out->msg( 'gloopcontrol-user-not-found', $name )
					->parse()
			));
		}

		$emailAuth = $user->getEmailAuthenticationTimestamp();
		$reg = $user->getRegistration();
		$lastEdit = MediaWikiServices::getInstance()->getUserEditTracker()->getLatestEditTimestamp( $user );
		$groups = [];
		foreach ( MediaWikiServices::getInstance()->getUserGroupManager()->getUserGroups( $user ) as $group ) {
			$groups[] = $out->msg( 'group-' . $group )->text();
		}
		$touched = $user->getTouched();

		$templateData = [
			'name' => $user->getName(),
			'space' => $out->msg( 'gloopcontrol-textformat-space' )->text(),
			'parentheses_start' => $out->msg( 'parentheses-start' )->text(),
			'id' => $user->getId(),
			'parentheses_end' => $out->msg( 'parentheses-end' )->text(),
			'registered_label' => $out->msg( 'prefs-registration' )->text(),
			'registered' => $reg ? $this->lang->userTimeAndDate( $reg, $user ) : $out->msg( 'gloopcontrol-user-unknown' )->text(),
			'email_label' => $out->msg( 'youremail' )->text(),
			'email' => $user->getEmail(),
			'email_authed' => $emailAuth ? $this->lang->userTimeAndDate( $emailAuth, $user ) : null,
			'email_confirmed' => $out->msg( 'gloopcontrol-user-email-confirmed' )->text(),
			'email_unconfirmed' => $out->msg( 'gloopcontrol-user-email-unconfirmed' )->text(),
			'real_label' => $out->msg( 'yourrealname' )->text(),
			'real' => $user->getRealName(),
			'block_label' => $out->msg( 'gloopcontrol-user-blocked' )->text(),
			'yes' => $out->msg( 'confirmable-yes' )->text(),
			'no' => $out->msg( 'confirmable-no' )->text(),
			'edits_label' => $out->msg( 'prefs-edits' )->text(),
			'edits' => $user->getEditCount(),
			'groups_label' => $out->msg( 'gloopcontrol-user-groups' )->text(),
			'groups' => sizeof( $groups ) > 0 ? implode( $out->msg( 'comma-separator' )->text(), $groups ) : $out->msg( 'gloopcontrol-user-none' )->text(),
			'touched_label' => $out->msg( 'gloopcontrol-user-touched' ),
			'touched' => $touched ? $this->lang->userTimeAndDate( $touched, $user ) : $out->msg( 'gloopcontrol-user-unknown' )->text(),
			'last_edit_label' => $out->msg( 'gloopcontrol-user-lastedit' ),
			'last_edit' => $lastEdit ? $this->lang->userTimeAndDate( $lastEdit, $user ) : $out->msg( 'gloopcontrol-user-unknown' )->text(),
			'ipaddress_label' => $out->msg( 'gloopcontrol-user-ipaddress' ),
			'checkuser_label' => $out->msg( 'checkuser' ),
			'unavailable' => $out->msg( 'gloopcontrol-user-unavailable' ),
			'migrated_no' => $out->msg( 'gloopcontrol-user-migrated-no' ),
			'migrated_yes' => $out->msg( 'gloopcontrol-user-migrated-yes' ),
			'2fa_label' => $out->msg( 'gloopcontrol-user-2fa' ),
			'rename' => Title::newFromText( 'Renameuser/' . $user->getName(), NS_SPECIAL )->getLinkURL(),
			'rename_button' => $out->msg( 'gloopcontrol-user-button-rename' )->text(),
			'change_email_url' => Title::newFromText( 'GloopControl/task', NS_SPECIAL )->getLinkURL( [
				'wptask' => '0',
				'wpusername' => $user->getName()
			] ),
			'change_email_button' => $out->msg( 'gloopcontrol-user-button-email' )->text(),
			'reassign_edits_url' => Title::newFromText( 'GloopControl/task', NS_SPECIAL )->getLinkURL( [
				'wptask' => '2',
				'wpreassign_username' => $user->getName()
			] ),
			'reassign_edits_button' => $out->msg( 'gloopcontrol-user-button-reassign' )->text(),
			'reset_password_url' => Title::newFromText( 'GloopControl/task', NS_SPECIAL )->getLinkURL( [
				'wptask' => '1',
				'wpusername' => $user->getName()
			] ),
			'reset_password_button' => $out->msg( 'gloopcontrol-user-button-password' )->text(),
		];

		// Get block information
		$block = $user->getBlock();
		if ( $block ) {
			$templateData['block_timestamp']=$this->lang->userTimeAndDate( $block->getTimestamp(), $user );
			$templateData['block_info'] = $out->msg(
				'gloopcontrol-user-blockinfo',
				$templateData['block_timestamp'],
				$block->getByName(),
				$this->lang->userTimeAndDate( $block->getExpiry(), $user )
			)->parse();
		}

		// Get info on relevant options
		$opts = MediaWikiServices::getInstance()->getUserOptionsLookup()->getOptions( $user );
		ksort( $opts );
		$templateData['opts'] = FormatJson::encode( $opts, true );

		// If certain extensions are enabled, we can integrate with them/show links.
		if ( $this->er->isLoaded( 'CheckUser' ) ) {
			$templateData['checkuser'] = Title::newFromText( 'CheckUser/' . $user->getName(), NS_SPECIAL )->getLinkURL();
		}

		if ( $this->er->isLoaded( 'OATHAuth' ) ) {
			$repo = MediaWikiServices::getInstance()->getService( 'OATHUserRepository' );
			$oathUser = $repo->findByUser( $user );
			$module = $oathUser->getModule();
			if ( !( $module instanceof IModule ) || $module->isEnabled( $oathUser ) === false ) {
				$templateData['2fa'] = $out->msg( 'confirmable-no' )->text();
			} else {
				$templateData['2fa'] = $out->msg( 'confirmable-yes' )->text();
			}
		}

		// Do some final database lookups for anything else
		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
		$res = $dbr->newSelectQueryBuilder()
			->select( [
				'user_password = "" as needs_migration'
			] )
			->from( 'user' )
			->where( [
				'user_id' => $user->getId()
			] )
			->fetchRow();

		if ( $res ) {
			if ( $this->er->isLoaded( 'MigrateUserAccount' ) && $res->needs_migration ) {
				$templateData['migration'] = 'yes';
			}
		}

		$html = $this->special->templateParser->processTemplate(
			'UserDetails',
			$templateData
		);
		$out->addHTML( $html );
	}
}
