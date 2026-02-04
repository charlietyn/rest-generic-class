# Config Matrix

| Config key | Declared in | Used in | Suggested action | Evidence |
| --- | --- | --- | --- | --- |
| rest-generic-class.logging.rest-generic-class.driver | config/rest-generic-class.php | (none in package) | INVESTIGATE | Declared in config, no callsites found. |
| rest-generic-class.logging.rest-generic-class.path | config/rest-generic-class.php | (none in package) | INVESTIGATE | Declared in config, no callsites found. |
| rest-generic-class.logging.rest-generic-class.level | config/rest-generic-class.php | (none in package) | INVESTIGATE | Declared in config, no callsites found. |
| rest-generic-class.logging.channel.driver | (missing) | src/Core/Providers/RestGenericClassServiceProvider.php | ADD/ALIGN | Used by ServiceProvider but not declared in config. |
| rest-generic-class.logging.channel.path | (missing) | src/Core/Providers/RestGenericClassServiceProvider.php | ADD/ALIGN | Used by ServiceProvider but not declared in config. |
| rest-generic-class.logging.channel.level | (missing) | src/Core/Providers/RestGenericClassServiceProvider.php | ADD/ALIGN | Used by ServiceProvider but not declared in config. |
| rest-generic-class.filtering.max_depth | config/rest-generic-class.php | src/Core/Services/BaseService.php | KEEP | Runtime use in BaseService. |
| rest-generic-class.filtering.max_conditions | config/rest-generic-class.php | src/Core/Services/BaseService.php | KEEP | Runtime use in BaseService. |
| rest-generic-class.filtering.strict_relations | config/rest-generic-class.php | src/Core/Services/BaseService.php | KEEP | Runtime use in BaseService. |
| rest-generic-class.filtering.allowed_operators | config/rest-generic-class.php | (none in package) | INVESTIGATE | Declared in config, no callsites found. |
| rest-generic-class.filtering.validate_columns | config/rest-generic-class.php | (none in package) | INVESTIGATE | Declared in config, no callsites found. |
| rest-generic-class.filtering.strict_column_validation | config/rest-generic-class.php | (none in package) | INVESTIGATE | Declared in config, no callsites found. |
| rest-generic-class.filtering.column_cache_ttl | config/rest-generic-class.php | (none in package) | INVESTIGATE | Declared in config, no callsites found. |
