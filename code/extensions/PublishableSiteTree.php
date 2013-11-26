<?php
/**
 * Bare-bones impelmentation of the StaticallyPublishable. Publishes just this page.
 */

class PublishableSiteTree extends Extension implements StaticallyPublishable {

	public function objectsToUpdate($context) {
		return new ArrayList(array($this->owner));
	}

	public function objectsToDelete($context) {
		return new ArrayList(array($this->owner));
	}

	public function urlsToCache() {
		return array($this->owner->Link() => 0);
	}

}

