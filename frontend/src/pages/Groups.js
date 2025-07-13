// Updated Groups.js with verification state tracking

import React, { useState, useEffect } from "react";
import axios from "axios";
import {
  UserGroupIcon,
  RefreshIcon,
  TrashIcon,
  CheckIcon,
} from "@heroicons/react/outline";
import { useTranslation } from "react-i18next";
import UsageAlert from "../components/UsageAlert";

const Groups = () => {
  const { t } = useTranslation();
  const [groups, setGroups] = useState([]);
  const [loading, setLoading] = useState(true);
  const [syncing, setSyncing] = useState(false);
  const [usage, setUsage] = useState(null);
  const [plan, setPlan] = useState(null);
  const [showAddGroupForm, setShowAddGroupForm] = useState(false);
  const [groupIdentifier, setGroupIdentifier] = useState("");
  const [adding, setAdding] = useState(false);
  const [verifyingGroupId, setVerifyingGroupId] = useState(null);
  const [verifiedGroups, setVerifiedGroups] = useState(new Set());

  useEffect(() => {
    fetchGroups();
    fetchUsageStats();
  }, []);

  const fetchGroups = async () => {
    try {
      const response = await axios.get(
        `${process.env.REACT_APP_API_URL}/api/groups`
      );
      setGroups(response.data);

      // Mark all fetched groups as verified (since they're in the system)
      const groupIds = response.data.map((group) => group.id || group._id);
      setVerifiedGroups(new Set(groupIds));
    } catch (error) {
      console.error("Failed to fetch groups:", error);
    } finally {
      setLoading(false);
    }
  };

  const fetchUsageStats = async () => {
    try {
      const response = await axios.get(
        `${process.env.REACT_APP_API_URL}/api/scheduled-posts/usage/stats`
      );
      setUsage(response.data.usage);
      setPlan(response.data.plan);
    } catch (error) {
      console.error("Failed to fetch usage stats:", error);
    }
  };

  const syncGroups = async () => {
    setSyncing(true);
    try {
      await axios.post(`${process.env.REACT_APP_API_URL}/api/groups/sync`);
      await fetchGroups();
      await fetchUsageStats();
      alert(t("groups_synced_successfully"));
    } catch (error) {
      console.error("Failed to sync groups:", error);
      if (error.response?.status === 403) {
        alert(error.response.data.message);
      } else {
        alert(t("failed_to_sync_groups"));
      }
    } finally {
      setSyncing(false);
    }
  };

  const addGroupManually = async (e) => {
    e.preventDefault();
    if (!groupIdentifier.trim()) return;

    setAdding(true);
    try {
      const response = await axios.post(
        `${process.env.REACT_APP_API_URL}/api/groups/add-manually`,
        {
          group_identifier: groupIdentifier.trim(),
        }
      );

      alert(t("group_added_successfully"));
      setGroupIdentifier("");
      setShowAddGroupForm(false);
      await fetchGroups();
      await fetchUsageStats();
    } catch (error) {
      console.error("Failed to add group:", error);
      if (error.response?.status === 403) {
        alert(error.response.data.message);
      } else if (error.response?.status === 404) {
        alert(t("group_not_found_check_bot"));
      } else {
        alert(t("failed_to_add_group"));
      }
    } finally {
      setAdding(false);
    }
  };

  const checkAdminStatus = async (group) => {
    const groupId = group.id || group._id;

    if (!groupId) {
      console.error("No group ID found:", group);
      alert(t("no_group_id_error"));
      return;
    }

    setVerifyingGroupId(groupId);

    try {
      const response = await axios.post(
        `${process.env.REACT_APP_API_URL}/api/groups/${groupId}/check-admin`
      );

      console.log("Admin check response:", response.data);

      if (response.data.is_admin) {
        // Mark this group as verified
        setVerifiedGroups((prev) => new Set([...prev, groupId]));

        if (response.data.already_added) {
          alert(t("admin_status_verified_and_updated"));
        } else if (response.data.newly_added) {
          alert(t("group_added_successfully"));
        } else {
          alert(t("admin_status_verified"));
        }

        // Refresh the groups list
        await fetchGroups();
        await fetchUsageStats();
      } else {
        alert(t("not_admin_in_group"));
      }
    } catch (error) {
      console.error("Failed to check admin status:", error);

      if (error.response?.status === 403) {
        alert(error.response.data.message);
      } else if (error.response?.status === 404) {
        alert(t("group_not_found"));
      } else {
        alert(t("failed_to_check_admin_status"));
      }
    } finally {
      setVerifyingGroupId(null);
    }
  };

  const removeGroup = async (group) => {
    const groupId = group.id || group._id;

    if (!groupId) {
      console.error("No group ID found for removal:", group);
      alert(t("no_group_id_error"));
      return;
    }

    if (!window.confirm(t("confirm_remove_group"))) {
      return;
    }

    try {
      await axios.delete(
        `${process.env.REACT_APP_API_URL}/api/groups/${groupId}`
      );

      // Remove from verified groups
      setVerifiedGroups((prev) => {
        const newSet = new Set(prev);
        newSet.delete(groupId);
        return newSet;
      });

      await fetchGroups();
      await fetchUsageStats();
      alert(t("group_removed_successfully"));
    } catch (error) {
      console.error("Failed to remove group:", error);
      alert(t("failed_to_remove_group"));
    }
  };

  const isGroupVerified = (group) => {
    const groupId = group.id || group._id;
    return verifiedGroups.has(groupId);
  };

  const isVerifying = (group) => {
    const groupId = group.id || group._id;
    return verifyingGroupId === groupId;
  };

  if (loading) {
    return (
      <div className="flex justify-center items-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-indigo-600"></div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {usage && plan && <UsageAlert usage={usage} plan={plan} />}

      <div className="flex justify-between items-center">
        <div>
          <h1 className="text-2xl font-semibold text-gray-900">
            {t("my_groups")}
          </h1>
          {usage && plan && (
            <p className="text-sm text-gray-500 mt-1">
              {t("using_groups", {
                used: usage.groups.used,
                limit: usage.groups.limit,
              })}
            </p>
          )}
        </div>
        <div className="flex gap-2">
          <button
            onClick={syncGroups}
            disabled={syncing}
            className="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50"
          >
            <RefreshIcon
              className={`-ml-1 mr-2 h-5 w-5 ${syncing ? "animate-spin" : ""}`}
            />
            {t("sync_groups")}
          </button>

          <button
            onClick={() => setShowAddGroupForm(!showAddGroupForm)}
            className="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md shadow-sm text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
          >
            {t("add_group_manually")}
          </button>
        </div>
      </div>

      {showAddGroupForm && (
        <div className="bg-white border rounded-lg p-4">
          <h3 className="text-lg font-medium mb-2">
            {t("add_group_manually")}
          </h3>
          <form onSubmit={addGroupManually} className="flex gap-2">
            <input
              type="text"
              value={groupIdentifier}
              onChange={(e) => setGroupIdentifier(e.target.value)}
              placeholder="@groupusername or group ID"
              className="flex-1 px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
            />
            <button
              type="submit"
              disabled={adding || !groupIdentifier.trim()}
              className="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 disabled:opacity-50"
            >
              {adding ? t("adding") : t("add")}
            </button>
            <button
              type="button"
              onClick={() => {
                setShowAddGroupForm(false);
                setGroupIdentifier("");
              }}
              className="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400"
            >
              {t("cancel")}
            </button>
          </form>
          <p className="text-sm text-gray-500 mt-2">
            {t("add_group_instructions")}
          </p>
        </div>
      )}

      <div className="bg-white shadow overflow-hidden sm:rounded-md">
        {groups.length === 0 ? (
          <div className="text-center py-12">
            <UserGroupIcon className="mx-auto h-12 w-12 text-gray-400" />
            <h3 className="mt-2 text-sm font-medium text-gray-900">
              {t("no_groups")}
            </h3>
            <p className="mt-1 text-sm text-gray-500">
              {t("add_bot_to_groups")}
            </p>
          </div>
        ) : (
          <ul className="divide-y divide-gray-200">
            {groups.map((group) => (
              <li key={group._id}>
                <div className="px-4 py-4 sm:px-6 flex items-center justify-between">
                  <div className="flex items-center">
                    {group.photo_url ? (
                      <img
                        className="h-12 w-12 rounded-full"
                        src={group.photo_url}
                        alt={group.title}
                      />
                    ) : (
                      <div className="h-12 w-12 rounded-full bg-gray-300 flex items-center justify-center">
                        <UserGroupIcon className="h-6 w-6 text-gray-600" />
                      </div>
                    )}
                    <div className="ml-4">
                      <div className="text-sm font-medium text-gray-900">
                        {group.title}
                      </div>
                      <div className="text-sm text-gray-500">
                        {group.member_count} {t("members")} • {group.type}
                      </div>
                      {isGroupVerified(group) && (
                        <div className="flex items-center text-xs text-green-600 mt-1">
                          <CheckIcon className="h-3 w-3 mr-1" />
                          {t("admin_verified")}
                        </div>
                      )}
                    </div>
                  </div>
                  <div className="flex items-center space-x-2">
                    {isGroupVerified(group) ? (
                      <button
                        onClick={() => checkAdminStatus(group)}
                        disabled={isVerifying(group)}
                        className="inline-flex items-center px-3 py-1.5 border border-green-300 text-xs font-medium rounded-md text-green-700 bg-green-50 hover:bg-green-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 disabled:opacity-50"
                      >
                        {isVerifying(group) ? (
                          <RefreshIcon className="h-3 w-3 animate-spin mr-1" />
                        ) : (
                          <CheckIcon className="h-3 w-3 mr-1" />
                        )}
                        {isVerifying(group) ? t("verifying") : t("re_verify")}
                      </button>
                    ) : (
                      <button
                        onClick={() => checkAdminStatus(group)}
                        disabled={isVerifying(group)}
                        className="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50"
                      >
                        {isVerifying(group) && (
                          <RefreshIcon className="h-3 w-3 animate-spin mr-1" />
                        )}
                        {isVerifying(group)
                          ? t("verifying")
                          : t("verify_admin")}
                      </button>
                    )}
                    <button
                      onClick={() => removeGroup(group)}
                      className="inline-flex items-center px-3 py-1.5 border border-red-300 text-xs font-medium rounded-md text-red-700 bg-white hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
                    >
                      <TrashIcon className="h-4 w-4" />
                    </button>
                  </div>
                </div>
              </li>
            ))}
          </ul>
        )}
      </div>

      <div className="bg-blue-50 border border-blue-200 rounded-md p-4">
        <h3 className="text-sm font-medium text-blue-800">
          {t("how_to_add_groups")}
        </h3>
        <ul className="mt-2 text-sm text-blue-700 list-disc list-inside">
          <li>{t("add_bot_as_admin")}</li>
          <li>{t("click_sync_groups")}</li>
          <li>{t("verify_admin_status")}</li>
        </ul>
      </div>
    </div>
  );
};

export default Groups;
