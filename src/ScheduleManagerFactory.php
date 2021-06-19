<?php


namespace App;


use App\Entity\Schedule;
use Doctrine\ORM\EntityManagerInterface;

class ScheduleManagerFactory
{
    private EntityManagerInterface $entityManager;
    private \SplObjectStorage $cache;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function createScheduleManager(Schedule $schedule): ScheduleManager
    {
        // Nota bene: tasks may get added or removed, but what are the invariants?
        //    invariants: from, to,

        // Since a manager cannot possibly know if the tasks collection has been modified,
        // we must reload all tasks into slots.

        // Slots are not recreated because the from and to dates are invariants and cannot be modified during
        // the schedule lifetime (no setters).

        if (!isset($this->cache[$schedule])) {
            $this->cache->attach($schedule, new ScheduleManager($schedule, $this->entityManager));
        } else {
            $this->cache[$schedule]->reloadTasks();
        }

        return $this->cache[$schedule];
    }
}
