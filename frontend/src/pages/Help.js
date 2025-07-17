// src/pages/Help.js - Comprehensive App Usage Guide

import React, { useState } from "react";
import { useTranslation } from "react-i18next";
import {
  ChevronDownIcon,
  ChevronRightIcon,
  UserGroupIcon,
  ClockIcon,
  CalendarIcon,
  ChartBarIcon,
  CogIcon,
  CreditCardIcon,
  ExclamationTriangleIcon,
  CheckCircleIcon,
  InformationCircleIcon,
  PlayIcon,
} from "@heroicons/react/outline";
import { LightBulbIcon } from "@heroicons/react/solid";

const Help = () => {
  const { t } = useTranslation();
  const [expandedSections, setExpandedSections] = useState({});

  const toggleSection = (sectionId) => {
    setExpandedSections((prev) => ({
      ...prev,
      [sectionId]: !prev[sectionId],
    }));
  };

  const Section = ({
    id,
    title,
    icon: Icon,
    children,
    defaultExpanded = false,
  }) => {
    const isExpanded = expandedSections[id] ?? defaultExpanded;

    return (
      <div className="border border-gray-200 rounded-lg mb-4">
        <button
          onClick={() => toggleSection(id)}
          className="w-full flex items-center justify-between p-4 bg-gray-50 hover:bg-gray-100 rounded-t-lg transition-colors"
        >
          <div className="flex items-center">
            <Icon className="h-5 w-5 text-indigo-600 mr-3" />
            <h2 className="text-lg font-medium text-gray-900">{title}</h2>
          </div>
          {isExpanded ? (
            <ChevronDownIcon className="h-5 w-5 text-gray-500" />
          ) : (
            <ChevronRightIcon className="h-5 w-5 text-gray-500" />
          )}
        </button>
        {isExpanded && (
          <div className="p-6 border-t border-gray-200">{children}</div>
        )}
      </div>
    );
  };

  const SubSection = ({ title, children }) => (
    <div className="mb-6">
      <h3 className="text-md font-medium text-gray-800 mb-3">{title}</h3>
      <div className="pl-4 border-l-2 border-indigo-100">{children}</div>
    </div>
  );

  const Step = ({ number, title, children }) => (
    <div className="flex mb-4">
      <div className="flex-shrink-0 w-6 h-6 bg-indigo-600 text-white rounded-full flex items-center justify-center text-sm font-medium mr-3 mt-1">
        {number}
      </div>
      <div className="flex-1">
        <h4 className="font-medium text-gray-800 mb-2">{title}</h4>
        <div className="text-gray-600">{children}</div>
      </div>
    </div>
  );

  const Alert = ({ type = "info", children }) => {
    const styles = {
      info: "bg-blue-50 border-blue-200 text-blue-800",
      warning: "bg-yellow-50 border-yellow-200 text-yellow-800",
      success: "bg-green-50 border-green-200 text-green-800",
      tip: "bg-purple-50 border-purple-200 text-purple-800",
    };

    const icons = {
      info: InformationCircleIcon,
      warning: ExclamationTriangleIcon,
      success: CheckCircleIcon,
      tip: LightBulbIcon,
    };

    const AlertIcon = icons[type];

    return (
      <div className={`border rounded-md p-4 mb-4 ${styles[type]}`}>
        <div className="flex">
          <AlertIcon className="h-5 w-5 mr-2 flex-shrink-0 mt-0.5" />
          <div className="text-sm">{children}</div>
        </div>
      </div>
    );
  };

  const CodeBlock = ({ children }) => (
    <div className="bg-gray-900 text-green-400 p-3 rounded-md font-mono text-sm mb-4">
      {children}
    </div>
  );

  return (
    <div className="max-w-4xl mx-auto">
      <div className="mb-8">
        <h1 className="text-3xl font-bold text-gray-900 mb-4">
          Telegram Scheduler - User Guide
        </h1>
        <p className="text-lg text-gray-600">
          Learn how to effectively use Telegram Scheduler to automate your
          message posting across multiple Telegram groups.
        </p>
      </div>

      {/* Getting Started */}
      <Section
        id="getting-started"
        title="Getting Started"
        icon={PlayIcon}
        defaultExpanded={true}
      >
        <SubSection title="What is Telegram Scheduler?">
          <p className="mb-4">
            Telegram Scheduler is a powerful tool that allows you to schedule
            and automatically send messages to multiple Telegram groups at
            specific times. Perfect for content creators, marketers, and
            community managers who need to reach multiple audiences efficiently.
          </p>

          <Alert type="info">
            <strong>Key Features:</strong> Multi-group posting, flexible
            scheduling, media support, revenue tracking, detailed analytics, and
            admin verification.
          </Alert>
        </SubSection>

        <SubSection title="System Requirements">
          <ul className="list-disc pl-6 space-y-2 text-gray-600">
            <li>A Telegram account</li>
            <li>Admin privileges in the Telegram groups you want to post to</li>
            <li>Modern web browser (Chrome, Firefox, Safari, Edge)</li>
            <li>Stable internet connection</li>
          </ul>
        </SubSection>

        <SubSection title="Quick Start">
          <Step number="1" title="Login with Telegram">
            Click the Telegram login button and authorize the application
            through your Telegram account.
          </Step>
          <Step number="2" title="Add Groups">
            Navigate to the Groups section and sync your groups or add them
            manually.
          </Step>
          <Step number="3" title="Schedule Your First Post">
            Go to "Schedule New Post" and create your first automated message.
          </Step>
          <Step number="4" title="Monitor Results">
            Check the Calendar and Statistics pages to track your posts'
            performance.
          </Step>
        </SubSection>
      </Section>

      {/* Groups Management */}
      <Section id="groups" title="Groups Management" icon={UserGroupIcon}>
        <SubSection title="Adding Groups">
          <p className="mb-4">
            Before you can schedule posts, you need to add your Telegram groups
            to the system.
          </p>

          <div className="mb-6">
            <h4 className="font-medium text-gray-800 mb-3">
              Method 1: Automatic Sync
            </h4>
            <Step number="1" title="Prepare Your Groups">
              Ensure the bot (@telegram_scheduler_bot) is added as an admin to
              your Telegram groups with permission to post messages.
            </Step>
            <Step number="2" title="Sync Groups">
              Click the "Sync Groups" button in the Groups section. The system
              will automatically discover groups where you and the bot are both
              admins.
            </Step>
            <Step number="3" title="Verify Results">
              Review the discovered groups and verify your admin status for each
              one.
            </Step>
          </div>

          <div className="mb-6">
            <h4 className="font-medium text-gray-800 mb-3">
              Method 2: Manual Addition
            </h4>
            <Step number="1" title="Get Group Information">
              Find your group's @username or ID from Telegram.
            </Step>
            <Step number="2" title="Add Manually">
              Click "Add Group Manually" and enter the group @username (e.g.,
              @mygroup) or ID.
            </Step>
            <Step number="3" title="Verify Admin Status">
              The system will verify you have admin permissions before adding
              the group.
            </Step>
          </div>

          <Alert type="warning">
            <strong>Important:</strong> You must be an admin in the group AND
            the bot must be added as an admin with posting permissions for the
            integration to work.
          </Alert>
        </SubSection>

        <SubSection title="Managing Groups">
          <ul className="list-disc pl-6 space-y-2 text-gray-600 mb-4">
            <li>
              <strong>Verify Admin Status:</strong> Click "Verify Admin" to
              check if you still have admin permissions
            </li>
            <li>
              <strong>Refresh Info:</strong> Update group member counts and
              other information
            </li>
            <li>
              <strong>Remove Groups:</strong> Remove groups you no longer want
              to use
            </li>
            <li>
              <strong>Auto-Verification:</strong> The system automatically
              checks admin status periodically
            </li>
          </ul>

          <Alert type="tip">
            Groups are automatically removed if you lose admin access, ensuring
            you only see groups you can actually post to.
          </Alert>
        </SubSection>

        <SubSection title="Troubleshooting Group Issues">
          <div className="space-y-4">
            <div>
              <h4 className="font-medium text-gray-800">Group Not Found</h4>
              <p className="text-gray-600">
                Ensure the bot is added to the group and you're using the
                correct @username.
              </p>
            </div>
            <div>
              <h4 className="font-medium text-gray-800">
                Admin Verification Failed
              </h4>
              <p className="text-gray-600">
                Check that you still have admin permissions in the group and the
                bot hasn't been removed.
              </p>
            </div>
            <div>
              <h4 className="font-medium text-gray-800">Sync Not Working</h4>
              <p className="text-gray-600">
                Try the manual add method or contact support if the issue
                persists.
              </p>
            </div>
          </div>
        </SubSection>
      </Section>

      {/* Scheduling Posts */}
      <Section id="scheduling" title="Scheduling Posts" icon={ClockIcon}>
        <SubSection title="Creating a Scheduled Post">
          <Step number="1" title="Select Groups">
            Choose one or multiple groups where you want to post. The system
            shows how many total messages will be sent.
          </Step>
          <Step number="2" title="Compose Message">
            Write your message text. HTML formatting is supported (bold, italic,
            links).
            <CodeBlock>
              Example: &lt;b&gt;Bold text&lt;/b&gt; and &lt;i&gt;italic
              text&lt;/i&gt;
            </CodeBlock>
          </Step>
          <Step number="3" title="Set Schedule Times">
            Add one or more schedule times. You can schedule the same message to
            go out multiple times.
          </Step>
          <Step number="4" title="Add Media (Optional)">
            Upload photos or videos to accompany your message. Supports multiple
            files.
          </Step>
          <Step number="5" title="Add Advertiser Info">
            Enter the advertiser's Telegram username and payment details for
            tracking.
          </Step>
          <Step number="6" title="Review and Schedule">
            Check all details and click "Schedule Post" to queue your message.
          </Step>
        </SubSection>

        <SubSection title="Multi-Group Posting">
          <p className="mb-4">
            One of the most powerful features is posting to multiple groups
            simultaneously.
          </p>

          <ul className="list-disc pl-6 space-y-2 text-gray-600 mb-4">
            <li>Select multiple groups using checkboxes</li>
            <li>The same message will be sent to all selected groups</li>
            <li>Each group counts as a separate message for billing</li>
            <li>All groups must be verified before posting</li>
          </ul>

          <Alert type="info">
            <strong>Example:</strong> Posting to 5 groups at 3 different times =
            15 total messages
          </Alert>
        </SubSection>

        <SubSection title="Supported Media Types">
          <ul className="list-disc pl-6 space-y-2 text-gray-600 mb-4">
            <li>
              <strong>Images:</strong> PNG, JPG, GIF (up to 50MB each)
            </li>
            <li>
              <strong>Videos:</strong> MP4, AVI, MOV (up to 50MB each)
            </li>
            <li>
              <strong>Multiple Files:</strong> You can attach several media
              files to one post
            </li>
            <li>
              <strong>Media Groups:</strong> Multiple files are sent as a media
              group in Telegram
            </li>
          </ul>

          <Alert type="warning">
            Keep file sizes reasonable for better delivery performance. Large
            files may take longer to send.
          </Alert>
        </SubSection>

        <SubSection title="Message Scheduling Rules">
          <ul className="list-disc pl-6 space-y-2 text-gray-600">
            <li>Schedule times must be at least 5 minutes in the future</li>
            <li>All times are in your account timezone (set in Settings)</li>
            <li>You can schedule the same message multiple times</li>
            <li>Posts can only be edited while in "Pending" status</li>
            <li>Messages are processed every minute by the system</li>
          </ul>
        </SubSection>
      </Section>

      {/* Calendar View */}
      <Section id="calendar" title="Calendar View" icon={CalendarIcon}>
        <SubSection title="Understanding the Calendar">
          <p className="mb-4">
            The calendar provides a visual overview of your scheduled posts and
            helps you find optimal posting times.
          </p>

          <ul className="list-disc pl-6 space-y-2 text-gray-600 mb-4">
            <li>
              <strong>Color Coding:</strong> Different colors represent post
              status (pending, sent, failed)
            </li>
            <li>
              <strong>Time Display:</strong> Shows exact scheduled times in your
              timezone
            </li>
            <li>
              <strong>Group Filtering:</strong> Filter by specific group or view
              all groups
            </li>
            <li>
              <strong>Available Slots:</strong> Green slots show when you can
              schedule new posts
            </li>
          </ul>
        </SubSection>

        <SubSection title="Using Calendar Features">
          <div className="space-y-4">
            <div>
              <h4 className="font-medium text-gray-800">Filtering by Group</h4>
              <p className="text-gray-600">
                Select a specific group to see only posts scheduled for that
                group and available times that don't conflict with that group's
                schedule.
              </p>
            </div>
            <div>
              <h4 className="font-medium text-gray-800">Available Slots</h4>
              <p className="text-gray-600">
                Toggle "Show Available Slots" to see 30-minute windows where you
                can schedule new posts without conflicts.
              </p>
            </div>
            <div>
              <h4 className="font-medium text-gray-800">Event Details</h4>
              <p className="text-gray-600">
                Click on any scheduled post to see details including groups,
                advertiser, amount, and content preview.
              </p>
            </div>
          </div>
        </SubSection>

        <SubSection title="Calendar Views">
          <ul className="list-disc pl-6 space-y-2 text-gray-600">
            <li>
              <strong>Month View:</strong> Overview of the entire month
            </li>
            <li>
              <strong>Week View:</strong> Detailed view with hourly slots
            </li>
            <li>
              <strong>Day View:</strong> Focus on a single day (mobile-friendly)
            </li>
          </ul>
        </SubSection>
      </Section>

      {/* Statistics */}
      <Section
        id="statistics"
        title="Statistics & Analytics"
        icon={ChartBarIcon}
      >
        <SubSection title="Overall Statistics">
          <p className="mb-4">
            Track your posting performance with comprehensive analytics.
          </p>

          <ul className="list-disc pl-6 space-y-2 text-gray-600 mb-4">
            <li>
              <strong>Total Posts:</strong> Number of posts you've scheduled
            </li>
            <li>
              <strong>Messages Sent:</strong> Successfully delivered messages
            </li>
            <li>
              <strong>Total Revenue:</strong> Sum of all advertiser payments
            </li>
            <li>
              <strong>Success Rate:</strong> Percentage of successful deliveries
            </li>
          </ul>
        </SubSection>

        <SubSection title="Monthly Trends">
          <p className="mb-4">
            View 6-month trends showing your posting volume and revenue over
            time. This helps identify:
          </p>
          <ul className="list-disc pl-6 space-y-2 text-gray-600">
            <li>Seasonal patterns in your posting activity</li>
            <li>Revenue growth or decline trends</li>
            <li>Optimal months for advertising campaigns</li>
          </ul>
        </SubSection>

        <SubSection title="Top Advertisers">
          <p className="mb-4">
            See which advertisers are your biggest revenue sources. Useful for:
          </p>
          <ul className="list-disc pl-6 space-y-2 text-gray-600">
            <li>Identifying your most valuable clients</li>
            <li>Prioritizing advertiser relationships</li>
            <li>Setting pricing strategies</li>
          </ul>
        </SubSection>

        <SubSection title="Group Performance">
          <p className="mb-4">
            Detailed statistics for each group help you understand:
          </p>
          <ul className="list-disc pl-6 space-y-2 text-gray-600">
            <li>
              <strong>Post Volume:</strong> How often each group is used
            </li>
            <li>
              <strong>Message Delivery:</strong> Success rate per group
            </li>
            <li>
              <strong>Revenue Distribution:</strong> Income generated per group
            </li>
            <li>
              <strong>Activity Patterns:</strong> When each group was last used
            </li>
          </ul>

          <Alert type="tip">
            Use group statistics to optimize your posting strategy and identify
            your most valuable channels.
          </Alert>
        </SubSection>
      </Section>

      {/* Subscription Plans */}
      <Section
        id="subscription"
        title="Subscription Plans"
        icon={CreditCardIcon}
      >
        <SubSection title="Plan Types">
          <div className="space-y-4 mb-6">
            <div className="border rounded-lg p-4">
              <h4 className="font-medium text-gray-800 mb-2">Free Plan</h4>
              <ul className="text-gray-600 text-sm space-y-1">
                <li>• 1 group</li>
                <li>• 3 messages per month</li>
                <li>• Basic features</li>
                <li>• Perfect for testing</li>
              </ul>
            </div>
            <div className="border rounded-lg p-4">
              <h4 className="font-medium text-gray-800 mb-2">Pro Plan</h4>
              <ul className="text-gray-600 text-sm space-y-1">
                <li>• 10 groups</li>
                <li>• 500 messages per month</li>
                <li>• Advanced scheduling</li>
                <li>• Priority support</li>
              </ul>
            </div>
            <div className="border rounded-lg p-4">
              <h4 className="font-medium text-gray-800 mb-2">Ultra Plan</h4>
              <ul className="text-gray-600 text-sm space-y-1">
                <li>• 50 groups</li>
                <li>• 5000 messages per month</li>
                <li>• All features</li>
                <li>• Dedicated support</li>
              </ul>
            </div>
          </div>
        </SubSection>

        <SubSection title="Usage Tracking">
          <p className="mb-4">Monitor your usage to avoid hitting limits:</p>
          <ul className="list-disc pl-6 space-y-2 text-gray-600">
            <li>Groups usage resets when you upgrade/downgrade</li>
            <li>Message count resets monthly on your billing date</li>
            <li>Usage alerts appear when you're near limits</li>
            <li>Overages are prevented - you can't exceed your plan limits</li>
          </ul>

          <Alert type="info">
            <strong>Message Calculation:</strong> Each group + time combination
            counts as one message. Example: 3 groups × 2 times = 6 messages.
          </Alert>
        </SubSection>

        <SubSection title="Billing Information">
          <ul className="list-disc pl-6 space-y-2 text-gray-600">
            <li>All plans are billed monthly</li>
            <li>Payments are processed securely through Stripe</li>
            <li>You can cancel anytime (access continues until period end)</li>
            <li>Downgrades take effect at the next billing cycle</li>
            <li>View payment history in the Subscription section</li>
          </ul>
        </SubSection>
      </Section>

      {/* Settings */}
      <Section id="settings" title="Settings & Configuration" icon={CogIcon}>
        <SubSection title="Account Settings">
          <div className="space-y-4">
            <div>
              <h4 className="font-medium text-gray-800">Timezone</h4>
              <p className="text-gray-600">
                Set your timezone to ensure scheduled posts are sent at the
                correct local time. All scheduling interfaces will display times
                in your selected timezone.
              </p>
            </div>
            <div>
              <h4 className="font-medium text-gray-800">Language</h4>
              <p className="text-gray-600">
                Choose your preferred language for the interface. Changes apply
                immediately. You can also change language using the globe icon
                in the top menu.
              </p>
            </div>
            <div>
              <h4 className="font-medium text-gray-800">Currency</h4>
              <p className="text-gray-600">
                Select your preferred currency for revenue tracking and
                statistics display. Exchange rates are updated daily for
                accurate reporting.
              </p>
            </div>
          </div>
        </SubSection>

        <SubSection title="Privacy & Security">
          <ul className="list-disc pl-6 space-y-2 text-gray-600">
            <li>
              Your Telegram data is only used for authentication and group
              management
            </li>
            <li>
              Messages and media are stored securely and deleted after sending
            </li>
            <li>Admin status is verified regularly to maintain security</li>
            <li>
              You can revoke access anytime through your Telegram settings
            </li>
          </ul>

          <Alert type="info">
            We take privacy seriously. Read our Privacy Policy for complete
            details on data handling.
          </Alert>
        </SubSection>
      </Section>

      {/* Troubleshooting */}
      <Section
        id="troubleshooting"
        title="Troubleshooting"
        icon={ExclamationTriangleIcon}
      >
        <SubSection title="Common Issues">
          <div className="space-y-6">
            <div>
              <h4 className="font-medium text-gray-800 text-red-600">
                Posts Not Sending
              </h4>
              <ul className="list-disc pl-6 space-y-1 text-gray-600 mt-2">
                <li>
                  Check if you still have admin permissions in the target groups
                </li>
                <li>
                  Verify the bot is still in the groups and has posting
                  permissions
                </li>
                <li>Ensure your account hasn't reached message limits</li>
                <li>Check the Calendar for any error indicators</li>
              </ul>
            </div>

            <div>
              <h4 className="font-medium text-gray-800 text-red-600">
                Groups Disappeared
              </h4>
              <ul className="list-disc pl-6 space-y-1 text-gray-600 mt-2">
                <li>
                  This usually means you lost admin access to those groups
                </li>
                <li>Ask other admins to reinvite you with admin permissions</li>
                <li>
                  The system automatically removes groups where you're not an
                  admin
                </li>
              </ul>
            </div>

            <div>
              <h4 className="font-medium text-gray-800 text-red-600">
                Can't Upload Media
              </h4>
              <ul className="list-disc pl-6 space-y-1 text-gray-600 mt-2">
                <li>Check file size (must be under 50MB)</li>
                <li>
                  Ensure file format is supported (PNG, JPG, GIF, MP4, AVI, MOV)
                </li>
                <li>Try uploading one file at a time</li>
                <li>Check your internet connection</li>
              </ul>
            </div>

            <div>
              <h4 className="font-medium text-gray-800 text-red-600">
                Timezone Issues
              </h4>
              <ul className="list-disc pl-6 space-y-1 text-gray-600 mt-2">
                <li>Update your timezone in Settings</li>
                <li>Remember that times are displayed in YOUR timezone</li>
                <li>Consider daylight saving time changes</li>
              </ul>
            </div>
          </div>
        </SubSection>

        <SubSection title="Getting Help">
          <div className="space-y-4">
            <div>
              <h4 className="font-medium text-gray-800">Check System Status</h4>
              <p className="text-gray-600">
                Visit our status page to see if there are any ongoing service
                issues.
              </p>
            </div>
            <div>
              <h4 className="font-medium text-gray-800">Contact Support</h4>
              <p className="text-gray-600">
                For issues not covered in this guide, contact our support team
                with:
              </p>
              <ul className="list-disc pl-6 space-y-1 text-gray-600 mt-2">
                <li>Detailed description of the problem</li>
                <li>Screenshots if applicable</li>
                <li>Your account username</li>
                <li>Steps you've already tried</li>
              </ul>
            </div>
          </div>

          <Alert type="tip">
            <strong>Pro Tip:</strong> Before contacting support, try refreshing
            the page and checking your admin status in groups.
          </Alert>
        </SubSection>
      </Section>

      {/* Best Practices */}
      <Section id="best-practices" title="Best Practices" icon={LightBulbIcon}>
        <SubSection title="Scheduling Strategy">
          <ul className="list-disc pl-6 space-y-2 text-gray-600 mb-4">
            <li>
              <strong>Optimal Timing:</strong> Research when your audience is
              most active
            </li>
            <li>
              <strong>Avoid Conflicts:</strong> Use the calendar to prevent
              overlapping posts
            </li>
            <li>
              <strong>Test Different Times:</strong> Experiment to find the best
              engagement times
            </li>
            <li>
              <strong>Plan Ahead:</strong> Schedule posts during business hours
              for next-day delivery
            </li>
          </ul>
        </SubSection>

        <SubSection title="Content Guidelines">
          <ul className="list-disc pl-6 space-y-2 text-gray-600 mb-4">
            <li>
              <strong>Clear Messaging:</strong> Write concise, engaging content
            </li>
            <li>
              <strong>Call to Action:</strong> Include clear next steps for
              readers
            </li>
            <li>
              <strong>Visual Appeal:</strong> Use high-quality images and videos
            </li>
            <li>
              <strong>Compliance:</strong> Follow Telegram's terms of service
            </li>
          </ul>
        </SubSection>

        <SubSection title="Group Management">
          <ul className="list-disc pl-6 space-y-2 text-gray-600 mb-4">
            <li>
              <strong>Regular Verification:</strong> Check admin status weekly
            </li>
            <li>
              <strong>Bot Permissions:</strong> Ensure the bot has necessary
              permissions
            </li>
            <li>
              <strong>Group Activity:</strong> Monitor which groups perform best
            </li>
            <li>
              <strong>Audience Relevance:</strong> Match content to group
              demographics
            </li>
          </ul>
        </SubSection>

        <SubSection title="Revenue Optimization">
          <ul className="list-disc pl-6 space-y-2 text-gray-600">
            <li>
              <strong>Track Performance:</strong> Use statistics to identify
              top-performing groups
            </li>
            <li>
              <strong>Pricing Strategy:</strong> Adjust rates based on group
              size and engagement
            </li>
            <li>
              <strong>Client Relationships:</strong> Maintain good relationships
              with repeat advertisers
            </li>
            <li>
              <strong>Growth Planning:</strong> Expand to more groups as your
              business grows
            </li>
          </ul>
        </SubSection>
      </Section>

      {/* Footer */}
      <div className="mt-12 text-center text-gray-500 border-t pt-8">
        <p>
          Need more help? Contact our support team or check our
          <a href="#" className="text-indigo-600 hover:text-indigo-800 ml-1">
            FAQ section
          </a>
        </p>
      </div>
    </div>
  );
};

export default Help;
