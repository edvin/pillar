# 🗃️ Event Store

Pillar’s event store is a **pluggable abstraction** that supports streaming domain events efficiently using PHP
generators.  
The default implementation, `DatabaseEventStore`, persists domain events in a database table — but you can replace it
with any other backend such as Kafka, DynamoDB, or S3.