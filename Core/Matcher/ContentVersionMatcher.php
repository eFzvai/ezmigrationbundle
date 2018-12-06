<?php

namespace Kaliop\eZMigrationBundle\Core\Matcher;

use eZ\Publish\API\Repository\Repository;
use eZ\Publish\API\Repository\Values\Content\Content;
use \eZ\Publish\API\Repository\Values\Content\VersionInfo;
use eZ\Publish\Core\Base\Exceptions\NotFoundException;
use Kaliop\eZMigrationBundle\API\MatcherInterface;
use Kaliop\eZMigrationBundle\API\Collection\VersionInfoCollection;

class ContentVersionMatcher extends RepositoryMatcher implements MatcherInterface
{
    const MATCH_STATUS_DRAFT = 'draft';
    const MATCH_STATUS_PUBLISHED = 'published';
    const MATCH_STATUS_ARCHIVED = 'archived';

    const MATCH_STATUS = 'version_status';
    const MATCH_VERSION = 'version';

    const STATUS_MAP = array(
        self::MATCH_STATUS_DRAFT => VersionInfo::STATUS_DRAFT,
        self::MATCH_STATUS_PUBLISHED => VersionInfo::STATUS_PUBLISHED,
        self::MATCH_STATUS_ARCHIVED => VersionInfo::STATUS_ARCHIVED
    );

    protected $allowedConditions = array(
        self::MATCH_ALL, self::MATCH_AND, self::MATCH_OR, self::MATCH_NOT,
        self::MATCH_STATUS, self::MATCH_VERSION,
        // aliases
        'status'
    );
    protected $returns = 'VersionInfo';

    protected $contentMatcher;

    public function __construct(Repository $repository, MatcherInterface $contentMatcher)
    {
        $this->repository = $repository;
        $this->contentMatcher = $contentMatcher;
    }

    public function match(array $contentConditions, array $versionConditions = array(), $sort = array(), $offset = 0, $limit = 0)
    {
        $versions = array();

        $contentCollection = $this->contentMatcher->match($contentConditions, $sort, $offset, $limit);
        foreach($contentCollection as $content) {
            $versions = array_merge($versions, $this->matchContentVersions($versionConditions, $content));
        }

        return new VersionInfoCollection($versions);
    }

    /**
     * Like match, but will throw an exception if there are 0 or more than 1 items matching
     *
     * @param array $conditions
     * @return mixed
     * @throws \Exception
     */
    public function matchOne(array $contentConditions, array $versionConditions = array(), $sort = array(), $offset = 0)
    {
        $results = $this->match($contentConditions, $versionConditions, $sort, $offset, 2);
        $count = count($results);
        if ($count !== 1) {
            throw new \Exception("Found $count " . $this->returns . " when expected exactly only one to match the conditions");
        }
        return reset($results);
    }

    /**
     * @param array $versionConditions
     * @param Content $content
     * @return VersionInfo[] key: obj_id/version_no
     */
    public function matchContentVersions(array $versionConditions, Content $content)
    {
        $this->validateConditions($versionConditions);

        foreach ($versionConditions as $key => $values) {

            if (!is_array($values)) {
                $values = array($values);
            }

            switch ($key) {
                case 'status':
                case self::MATCH_STATUS:
                    return $this->findContentVersionsByStatus($content, $values);

                case self::MATCH_VERSION:
                    return $this->findContentVersionsByVersionNo($content, $values);

                case self::MATCH_ALL:
                    return $this->findAllContentVersions($content);

                case self::MATCH_AND:
                    return $this->matchAnd($values, $content);

                case self::MATCH_OR:
                    return $this->matchOr($values, $content);

                case self::MATCH_NOT:
                    return array_diff_key($this->findAllContentVersions($content), $this->matchContentVersions($values, $content));
            }
        }
    }

    protected function matchAnd(array $conditionsArray, $content = null)
    {
        /// @todo introduce proper re-validation of all child conditions
        if (!is_array($conditionsArray) || !count($conditionsArray)) {
            throw new \Exception($this->returns . " can not be matched because no matching conditions found for 'and' clause.");
        }

        if (is_null($content)) {
            throw new \Exception($this->returns . " can not be matched because there was no content to match for 'and' clause.");
        }

        foreach ($conditionsArray as $conditions) {
            $out = $this->matchContentVersions($conditions, $content);
            if (!isset($results)) {
                $results = $out;
            } else {
                $results = array_intersect_key($results, $out);
            }
        }

        return $results;
    }

    protected function matchOr(array $conditionsArray, $content = null)
    {
        /// @todo introduce proper re-validation of all child conditions
        if (!is_array($conditionsArray) || !count($conditionsArray)) {
            throw new \Exception($this->returns . " can not be matched because no matching conditions found for 'or' clause.");
        }

        if (is_null($content)) {
            throw new \Exception($this->returns . " can not be matched because there was no content to match for 'or' clause.");
        }

        $results = array();
        foreach ($conditionsArray as $conditions) {
            $out = $this->matchContentVersions($conditions, $content);
            $results = array_replace($results, $out);
        }

        return $results;
    }

    /**
     * @param Content $content
     * @param string[] $values
     * @return VersionInfo[] key: obj_id/version_no, sorted in increasing version no.
     */
    protected function findContentVersionsByStatus(Content $content, array $values)
    {
        $versions = array();
        foreach ($this->findAllContentVersions($content) as $versionKey => $versionInfo) {
            foreach($values as $acceptedStatus) {
                if ($versionInfo->status == self::STATUS_MAP[$acceptedStatus]) {
                    $versions[$versionKey] = $versionInfo;
                    break;
                }
            }
        }
        return $versions;
    }

    /**
     * @param Content $content
     * @param int[] $values
     * @return VersionInfo[] key: obj_id/version_no, sorted in increasing version no.
     */
    protected function findContentVersionsByVersionNo(Content $content, array $values)
    {
        $versions = array();
        $contentVersions = $this->findAllContentVersions($content);
        $contentVersionsCount = count($contentVersions);
        $i = 0;
        foreach ($contentVersions as $versionKey => $versionInfo) {
            foreach($values as $acceptedVersionNo) {
                if ($acceptedVersionNo > 0 ) {
                    if ($acceptedVersionNo == $versionInfo->versionNo) {
                        $versions[$versionKey] = $versionInfo;
                        break;
                    }
                } else {
                    // negative $acceptedVersionNo means 'leave the last X versions', eg: -1 = leave the last version
                    if ($i < $contentVersionsCount + $acceptedVersionNo)  {
                        $versions[$versionKey] = $versionInfo;
                        break;

                    }
                }
            }
            $i++;
        }
        return $versions;
    }

    /**
     * @param Content $content
     * @return VersionInfo[] key: obj_id/version_no, sorted in increasing version no.
     */
    protected function findAllContentVersions(Content $content)
    {
        $contentVersions = $this->repository->getContentService()->loadVersions($content->contentInfo);
        // different eZ kernels apparently sort versions in different order...
        $sortedVersions = array();
        foreach($contentVersions as $versionInfo) {
            $sortedVersions[$content->contentInfo->id . '/' . $versionInfo->versionNo] = $versionInfo;
        }
        ksort($sortedVersions);

        return $sortedVersions;
    }
}
