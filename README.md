Conagrivet
======================

## Requirements
- PHP 8.0 or higher
- MySQL 5.6 or higher

## Development Setup
1. Clone the repository


## Validation Notes
- Validation happens at the database, ORM and app level.
- App level validation is performed by Symfony's Validator
- Entity level validation is performed by the ORM
- data validation is performed by simple database constraints (e.g. indexes)

The Validator is meant to be used to validate entities populated with user data (e.g. submitted forms).
ORM validation (e.g. @PreUpdate) is meant to enforce complex constraints at the ORM level
Database validation is meant for simple constraints.

Data originates either from user input (e.g. google sheets, forms), or is inserted programmatically.
In all cases, both Validator and ORM checks should be performed.
Further user-level checks performed via CLI or controller actions are meant to be 'soft' checks which do not
represent hard data constraints to be enforced. However, these checks could become hard constraints.

In general, when entities are created from user data (e.g. google sheets, forms) both Validator and ORM checks
must be performed. When entities are created/updated programmatically, the Validator may not be run.

## CLI
- `bin/console app:import-recipients --from-sheet=1Gc2DLrdOpnH9hQPI2eaqsB9lkFpVsF6krU_cLcJO5Bw/599080703`
- `bin/console app:import-consultants --from-sheet=1Gc2DLrdOpnH9hQPI2eaqsB9lkFpVsF6krU_cLcJO5Bw/245445236`
- `bin/console app:import-services --from-sheet=1Gc2DLrdOpnH9hQPI2eaqsB9lkFpVsF6krU_cLcJO5Bw/1739363178`
- `bin/console app:import-contracts --from-sheet=1Gc2DLrdOpnH9hQPI2eaqsB9lkFpVsF6krU_cLcJO5Bw/1659309557` 


## Deployment
- `bin/console cache:clear`
- `APP_ENV=prod ./composer.phar install --no-dev --classmap-authoritative`
- `./composer.phar dump-autoload --no-dev --classmap-authoritative`
- `./composer.phar dump-env prod`. Note that real environment variables always win over ones declared in .env files.
- `./cachetool.phar opcache:reset`


## Entity-Relationship Model

### Task
 - tasks are never reassigned between schedules?

### Schedule Management

After a schedule manager has been instantiated for a Schedule, task operations (Add/remove)
*must* be performed through the manager. This is required in order to prevent synchronization 
issues between the manager and the schedule tasks.
