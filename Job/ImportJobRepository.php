<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CsvImporter\Job;

use CsvImporter\Import\ImportMode;
use CsvImporter\Import\RowOutcome;
use CsvImporter\Model\CsvImporterJob;
use CsvImporter\Model\CsvImporterJobQuery;
use CsvImporter\Model\CsvImporterJobRow;
use CsvImporter\Model\CsvImporterJobRowQuery;
use CsvImporter\Model\Map\CsvImporterJobTableMap;
use CsvImporter\Report\ReportMessage;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Propel;

final readonly class ImportJobRepository
{
    public function create(?int $profileId, string $sourcePath, string $sourceName, ImportMode $mode, bool $dryRun, ?int $adminId): CsvImporterJob
    {
        $job = (new CsvImporterJob())
            ->setProfileId($profileId)
            ->setAdminId($adminId)
            ->setStatus(JobStatus::Queued->value)
            ->setMode($mode->value)
            ->setDryRun($dryRun ? 1 : 0)
            ->setSourceName($sourceName)
            ->setSourcePath($sourcePath);
        $job->save();

        return $job;
    }

    public function createDraft(int $profileId, string $sourcePath, string $sourceName, ImportMode $mode, ?int $adminId): CsvImporterJob
    {
        $job = (new CsvImporterJob())
            ->setProfileId($profileId)
            ->setAdminId($adminId)
            ->setStatus(JobStatus::Draft->value)
            ->setMode($mode->value)
            ->setDryRun(1)
            ->setSourceName($sourceName)
            ->setSourcePath($sourcePath);
        $job->save();

        return $job;
    }

    public function find(int $id): ?CsvImporterJob
    {
        return CsvImporterJobQuery::create()->findPk($id);
    }

    /**
     * @return list<CsvImporterJob>
     */
    public function recent(int $limit): array
    {
        return array_values(CsvImporterJobQuery::create()->orderById(Criteria::DESC)->limit($limit)->find()->getData());
    }

    public function findDraftOf(string $sourcePath): ?CsvImporterJob
    {
        return CsvImporterJobQuery::create()->filterBySourcePath($sourcePath)->filterByStatus(JobStatus::Draft->value)->orderById(Criteria::DESC)->findOne();
    }

    public function hasLiveJobOn(string $sourcePath): bool
    {
        return CsvImporterJobQuery::create()
            ->filterBySourcePath($sourcePath)
            ->filterByStatus([JobStatus::Queued->value, JobStatus::Running->value], Criteria::IN)
            ->count() > 0;
    }

    public function hasRealRunSince(string $sourcePath, \DateTimeInterface $since): bool
    {
        return CsvImporterJobQuery::create()
            ->filterBySourcePath($sourcePath)
            ->filterByDryRun(0)
            ->filterByStatus(JobStatus::Draft->value, Criteria::NOT_EQUAL)
            ->filterByCreatedAt($since, Criteria::GREATER_EQUAL)
            ->count() > 0;
    }

    /**
     * Lines of the report after the given id, in the order they were written: a page
     * costs the same however far into the report it is.
     *
     * @return list<CsvImporterJobRow>
     */
    public function linesAfter(CsvImporterJob $job, int $afterId, int $limit): array
    {
        return array_values(CsvImporterJobRowQuery::create()->filterByJobId($job->getId())->filterById($afterId, Criteria::GREATER_THAN)->orderById()->limit($limit)->find()->getData());
    }

    public function abandon(CsvImporterJob $job, int $idleSeconds = 0): bool
    {
        return $this->leave($job, [JobStatus::Queued, JobStatus::Running], JobStatus::Failed, (new ReportMessage('The job was abandoned by an administrator'))->toJson(), $idleSeconds);
    }

    public function changeMode(CsvImporterJob $job, ImportMode $mode): void
    {
        $job->setMode($mode->value)->save();
    }

    public function hasLiveRealRun(string $sourcePath): bool
    {
        return CsvImporterJobQuery::create()
            ->filterBySourcePath($sourcePath)
            ->filterByDryRun(0)
            ->filterByStatus([JobStatus::Queued->value, JobStatus::Running->value], Criteria::IN)
            ->count() > 0;
    }

    public function countLines(CsvImporterJob $job, ?string $outcome): int
    {
        return $this->linesOf($job, $outcome)->count();
    }

    /**
     * @return list<CsvImporterJobRow>
     */
    public function lines(CsvImporterJob $job, ?string $outcome, int $offset, int $limit): array
    {
        return array_values($this->linesOf($job, $outcome)->orderByLineNumber()->orderById()->offset($offset)->limit($limit)->find()->getData());
    }

    /**
     * @return array<string, int>
     */
    public function lineCountsByOutcome(CsvImporterJob $job): array
    {
        $counts = [];

        foreach (CsvImporterJobRowQuery::create()->filterByJobId($job->getId())->withColumn('COUNT(*)', 'total')->select(['Outcome', 'total'])->groupByOutcome()->find() as $row) {
            $counts[(string) $row['Outcome']] = (int) $row['total'];
        }

        return $counts;
    }

    private function linesOf(CsvImporterJob $job, ?string $outcome): CsvImporterJobRowQuery
    {
        $query = CsvImporterJobRowQuery::create()->filterByJobId($job->getId());

        return null === $outcome ? $query : $query->filterByOutcome($outcome);
    }

    public function claim(CsvImporterJob $job): bool
    {
        $claimed = CsvImporterJobQuery::create()
            ->filterById($job->getId())
            ->filterByStatus(JobStatus::Queued->value)
            ->update(['Status' => JobStatus::Running->value, 'StartedAt' => date('Y-m-d H:i:s'), 'UpdatedAt' => date('Y-m-d H:i:s')]);

        if ($claimed > 0) {
            $job->reload();
        }

        return $claimed > 0;
    }

    public function markRunning(CsvImporterJob $job, int $totalRows): void
    {
        $job
            ->setStatus(JobStatus::Running->value)
            ->setTotalRows($totalRows)
            ->save();
    }

    public function saveProgress(CsvImporterJob $job, int $processed, array $counts): void
    {
        $job
            ->setProcessedRows($processed)
            ->setCreatedRows($counts[RowOutcome::Created->value] ?? 0)
            ->setUpdatedRows($counts[RowOutcome::Updated->value] ?? 0)
            ->setUnchangedRows($counts[RowOutcome::Unchanged->value] ?? 0)
            ->setSkippedRows($counts[RowOutcome::Skipped->value] ?? 0)
            ->setFailedRows($counts[RowOutcome::Failed->value] ?? 0)
            ->save();
    }

    public function markDone(CsvImporterJob $job): void
    {
        $this->leave($job, [JobStatus::Running], JobStatus::Done);
    }

    public function markFailed(CsvImporterJob $job, ReportMessage $reason): void
    {
        $this->leave($job, [JobStatus::Queued, JobStatus::Running], JobStatus::Failed, $reason->toJson());
    }

    public function touch(CsvImporterJob $job): bool
    {
        CsvImporterJobQuery::create()->filterById($job->getId())->filterByStatus(JobStatus::Running->value)->update(['UpdatedAt' => date('Y-m-d H:i:s')]);

        return CsvImporterJobQuery::create()->filterById($job->getId())->filterByStatus(JobStatus::Running->value)->count() > 0;
    }

    public function refuseReplay(CsvImporterJob $job, ReportMessage $reason): void
    {
        CsvImporterJobQuery::create()
            ->filterById($job->getId())
            ->filterByStatus(JobStatus::Failed->value)
            ->update(['Error' => $reason->toJson(), 'UpdatedAt' => date('Y-m-d H:i:s')]);

        $job->reload();
    }

    public function requeue(CsvImporterJob $job): bool
    {
        $connection = Propel::getWriteConnection(CsvImporterJobTableMap::DATABASE_NAME);
        $connection->beginTransaction();

        try {
            $requeued = CsvImporterJobQuery::create()
                ->filterById($job->getId())
                ->filterByStatus(JobStatus::Failed->value)
                ->update(['Status' => JobStatus::Queued->value, 'UpdatedAt' => date('Y-m-d H:i:s'), 'Error' => null, 'FinishedAt' => null, 'StartedAt' => null, 'TotalRows' => 0, 'ProcessedRows' => 0, 'CreatedRows' => 0, 'UpdatedRows' => 0, 'UnchangedRows' => 0, 'SkippedRows' => 0, 'FailedRows' => 0]);

            if ($requeued > 0) {
                CsvImporterJobRowQuery::create()->filterByJobId($job->getId())->delete();
            }

            $connection->commit();
        } catch (\Throwable $failure) {
            $connection->rollBack();

            throw $failure;
        }

        if ($requeued > 0) {
            $job->reload();
        }

        return $requeued > 0;
    }

    /**
     * @param list<JobStatus> $from
     */
    private function leave(CsvImporterJob $job, array $from, JobStatus $to, ?string $error = null, int $idleSeconds = 0): bool
    {
        $now = date('Y-m-d H:i:s');
        $values = ['Status' => $to->value, 'FinishedAt' => $now, 'UpdatedAt' => $now];

        if (null !== $error) {
            $values['Error'] = $error;
        }

        $query = CsvImporterJobQuery::create()
            ->filterById($job->getId())
            ->filterByStatus(array_map(static fn (JobStatus $status): string => $status->value, $from), Criteria::IN);

        if ($idleSeconds > 0) {
            $query->filterByUpdatedAt(new \DateTimeImmutable(\sprintf('-%d seconds', $idleSeconds)), Criteria::LESS_EQUAL);
        }

        $left = $query->update($values) > 0;
        $job->reload();

        return $left;
    }

    public function addLine(CsvImporterJob $job, int $lineNumber, string $outcome, ?string $reference, ?ReportMessage $message): void
    {
        (new CsvImporterJobRow())
            ->setJobId($job->getId())
            ->setLineNumber($lineNumber)
            ->setOutcome($outcome)
            ->setReference(null === $reference ? null : mb_substr($reference, 0, 255))
            ->setMessage($message?->toJson())
            ->save();
    }
}
