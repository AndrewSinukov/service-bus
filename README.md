[![Build Status](https://travis-ci.org/php-service-bus/service-bus.svg?branch=v3.0)](https://travis-ci.org/php-service-bus/service-bus)
[![Code Coverage](https://scrutinizer-ci.com/g/php-service-bus/service-bus/badges/coverage.png?b=v3.0)](https://scrutinizer-ci.com/g/php-service-bus/service-bus/?branch=v3.0)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/php-service-bus/service-bus/badges/quality-score.png?b=v3.0)](https://scrutinizer-ci.com/g/php-service-bus/service-bus/?branch=v3.0)
[![SymfonyInsight](https://insight.symfony.com/projects/4c7edc8a-3dfc-432e-9749-35ecdfc927bb/mini.svg)](https://insight.symfony.com/projects/4c7edc8a-3dfc-432e-9749-35ecdfc927bb)
[![Latest Stable Version](https://poser.pugx.org/php-service-bus/service-bus/v/stable)](https://packagist.org/packages/php-service-bus/service-bus)
[![License](https://poser.pugx.org/php-service-bus/service-bus/license)](https://packagist.org/packages/php-service-bus/service-bus)

## What is it?
A concurrency (based on [Amp](https://github.com/amphp)) framework, that lets you implement an asynchronous messaging, a transparent workflow and control of long-lived business transactions by means of the Saga pattern. It implements the [message based architecture](https://www.enterpriseintegrationpatterns.com/patterns/messaging/Messaging.html) and it includes the following patterns: Saga, Publish\Subscribe, Message Bus.

#### Main Features
 - Concurrency
 - Asynchronous messaging (Publish\Subscribe pattern implementation)
 - Event-driven architecture
 - Distribution (messages can be handled by different processes)
   - Subscribers can be implemented on any programming language
 - High performance (Due to [cooperative multitasking](https://nikic.github.io/2012/12/22/Cooperative-multitasking-using-coroutines-in-PHP.html))
   - [Performance comparison with the "symfony/messenger"](https://github.com/php-service-bus/performance-comparison)
 - Orchestration of long-lived business transactions (for example, a checkout) with the help of [Saga Pattern](https://github.com/php-service-bus/service-bus/blob/master/doc/sagas.md)
 - Full history of aggregate changes ([EventSourcing](https://github.com/php-service-bus/service-bus/blob/master/doc/en_event_sourcing.md))

#### Scope of use
Basically, it is suitable for development of distributed applications. By using the Message Bus and Saga pattern it lets you decrease the coupling of contexts.

#### Get started
```
composer create-project php-service-bus/skeleton my-project
```
Demo application (WIP): [service-bus-demo](https://github.com/php-service-bus/demo)

#### Documentation


#### Requirements
  - PHP 7.2+
  - RabbitMQ
  - PostgreSQL 9.5+

## Security

If you discover any security related issues, please email [`dev@async-php.com`](mailto:dev@async-php.com) instead of using the issue tracker.

## License

The MIT License (MIT). Please see [LICENSE](LICENSE.md) for more information.
