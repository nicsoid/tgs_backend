// src/components/UsageAlert.js

import React from "react";
import { useTranslation } from "react-i18next";
import { ExclamationIcon } from "@heroicons/react/solid";
import { Link } from "react-router-dom";

const UsageAlert = ({ usage, plan }) => {
  const { t } = useTranslation();

  const groupsPercentage = (usage.groups_count / plan.limits.groups) * 100;
  const messagesPercentage =
    (usage.messages_sent_this_month / plan.limits.messages_per_month) * 100;

  if (groupsPercentage < 80 && messagesPercentage < 80) {
    return null;
  }

  return (
    <div className="rounded-md bg-yellow-50 p-4 mb-6">
      <div className="flex">
        <div className="flex-shrink-0">
          <ExclamationIcon className="h-5 w-5 text-yellow-400" />
        </div>
        <div className="ml-3">
          <h3 className="text-sm font-medium text-yellow-800">
            {t("usage_limit_warning")}
          </h3>
          <div className="mt-2 text-sm text-yellow-700">
            <p>
              {groupsPercentage >= 80 && (
                <>
                  {t("groups_usage_high", {
                    used: usage.groups_count,
                    limit: plan.limits.groups,
                  })}
                  <br />
                </>
              )}
              {messagesPercentage >= 80 && (
                <>
                  {t("messages_usage_high", {
                    used: usage.messages_sent_this_month,
                    limit: plan.limits.messages_per_month,
                  })}
                </>
              )}
            </p>
            <p className="mt-2">
              <Link
                to="/subscription"
                className="font-medium text-yellow-800 hover:text-yellow-900"
              >
                {t("upgrade_plan")} →
              </Link>
            </p>
          </div>
        </div>
      </div>
    </div>
  );
};

export default UsageAlert;
