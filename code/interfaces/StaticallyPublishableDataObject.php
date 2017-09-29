<?php
/**
 * Interface for statically publishable data objects.
 */
interface StaticallyPublishableDataObject
{
    /**
     * Returns the subsite the data object belongs to, or null if not required
     * @return Subsite
     */
    public function Subsite();

    /**
     * Returns the URLs the data object impacts.
     * @return array
     */
    public function dataObjectUrls();

    /**
     * Tells us if the Data Object should be published.
     * @return bool
     */
    public function isPublishable();
}
