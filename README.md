slack-agenda-app
================

This application integrates an agenda in Slack.

It makes it possible for your team members to:
* Browse your upcoming events
* Filter events based on their categories
* Register or unregister for events
* Get reminders the day before an event to which they registered

all this from Slack

An image is worth a thousand words, here is what it looks like:

    +---------------+  Read events            +----------+  Read events             +------------+
    | CalDav server | <---------------------> | This app | <----------------------> | Your Slack |
    +---------------+  Handle registrations   +----------+  Handle registrations    +------------+

What's the point
----------------

We developped this app because:

* Our volunteers did not wanted to use too many different tools (so they appreciated having everything in Slack)
* We want it to be convenient to filter on events of a given category (e.g.: only "remote" events) or to see only event still missing participants
* We did not had another convenient way to send a reminder

If you are in a similar situation you might find this useful

How to install
==============

Prerequisites: you will need

* A PHP web hosting (which has php-curl)
* A slack space for your team (of course)
* A caldav server (for instance you can use google calendar)
* PHP Composer (you can get it from [here](https://getcomposer.org/))

In a nutshell: to install it you need to

- Create the Slack app
- install those PHP scripts

Create the Slack app
--------------------

TODO (in particular, mention that a dedicated user should be created for the reminders)

Installing the PHP scripts
--------------------------
Get the code:

    curl -L https://github.com/Zero-Waste-Paris/slack-agenda-app/zipball/main > slack-agenda-app.zip
    unzip slack-agenda-app.zip
    rm slack-agenda-app.zip
    cd Zero-Waste-Paris-slack-agenda-app-*
    composer install --no-dev
    cp config.json.sample config.json

Edit config.json in order to put your Slack tokens and your caldav credentials (If you are using a google calendar you should follow this doc https://support.google.com/accounts/answer/185833?hl=fr )

Upload everything on your php hosting

How to use on a daily basis
===========================
Creating events
---------------

This part is not covered by this app. To create event you should go directly to your caldav server. (Note that Google Calendar UI
does not seem to support assigning categories to events. If you use Google Calendar and want to assign categories to an event you
should use a rich client, for instance [Thunderbird](https://www.thunderbird.net/en-US/) along with its [Lightning](https://www.thunderbird.net/en-US/calendar/) plugin)

Browsing events and registering to them
---------------------------------------

Your team member should add to herself the app on Slack (that you previously installed in your workspace). It will then find it
in the "Apps" section (TODO: add screenshots) and can do everything from here

Technical notes for admin
=========================

* Unless you are a really big organization the slack [rate limits](https://api.slack.com/changelog/2018-03-great-rate-limits) should be really more than enough
* The content of the CalDav server is cached on the web hosting (currently on the filesystem) so the number of requests to this backend should be limited
* TODO: how to get the logs


