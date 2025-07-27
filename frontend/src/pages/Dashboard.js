// src/pages/Dashboard.js - Fixed Version

import React, { useState, useEffect } from "react";
import { Link } from "react-router-dom";
import { useTranslation } from "react-i18next";
import axios from "axios";
import {
  ChartBarIcon,
  ClockIcon,
  UserGroupIcon,
  CalendarIcon,
  PlusIcon,
  ExclamationIcon,
} from "@heroicons/react/outline";
import { CircularProgressbar, buildStyles } from "react-circular-progressbar";
import "react-circular-progressbar/dist/styles.css";

const Dashboard = () => {
  const { t } = useTranslation();
  const [stats, setStats] = useState(null);
  const [usage, setUsage] = useState(null);
  const [recentPosts, setRecentPosts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    fetchDashboardData();
  }, []);

  const fetchDashboardData = async () => {
    try {
      setLoading(true);
      setError(null);

      // Try the new simplified dashboard endpoints first
      let statsData = null;
      let usageData = null;
      let postsData = [];

      // Method 1: Try new dashboard stats endpoint
      try {
        const dashboardRes = await axios.get(
          `${process.env.REACT_APP_API_URL}/api/dashboard/stats`
        );
        if (dashboardRes.data.success) {
          const data = dashboardRes.data.data;
          statsData = {
            overall: {
              total_posts: data.total_posts,
              total_sent: data.total_sent,
              total_revenue: data.total_revenue,
              currency: data.currency,
            },
          };
        }
      } catch (error) {
        console.warn("New dashboard endpoint failed, trying fallback:", error);
      }

      // Method 2: Fallback to original statistics endpoint
      if (!statsData) {
        try {
          const statsRes = await axios.get(
            `${process.env.REACT_APP_API_URL}/api/statistics`
          );
          statsData = statsRes.data;
        } catch (error) {
          console.warn("Statistics endpoint failed:", error);
          statsData = {
            overall: {
              total_posts: 0,
              total_sent: 0,
              total_revenue: 0,
              currency: "USD",
            },
          };
        }
      }

      // Fetch usage stats
      try {
        const usageRes = await axios.get(
          `${process.env.REACT_APP_API_URL}/api/scheduled-posts/usage/stats`
        );
        usageData = usageRes.data;
      } catch (error) {
        console.warn("Failed to fetch usage stats:", error);
        usageData = {
          usage: {
            groups: { used: 0, limit: 1, percentage: 0 },
            messages: { used: 0, limit: 3, percentage: 0 },
          },
          plan: {
            name: "free",
            display_name: "Free",
            limits: { groups: 1, messages_per_month: 3 },
          },
        };
      }

      // Try new recent posts endpoint first
      try {
        const recentRes = await axios.get(
          `${process.env.REACT_APP_API_URL}/api/dashboard/recent-posts`
        );
        if (recentRes.data.success) {
          postsData = recentRes.data.data;
        }
      } catch (error) {
        console.warn(
          "New recent posts endpoint failed, trying fallback:",
          error
        );
      }

      // Fallback to original posts endpoint
      if (postsData.length === 0) {
        try {
          const postsRes = await axios.get(
            `${process.env.REACT_APP_API_URL}/api/scheduled-posts?page=1`
          );
          postsData = Array.isArray(postsRes.data)
            ? postsRes.data.slice(0, 5)
            : (postsRes.data.data || []).slice(0, 5);
        } catch (error) {
          console.warn("Failed to fetch recent posts:", error);
          postsData = [];
        }
      }

      setStats(statsData);
      setUsage(usageData);
      setRecentPosts(postsData);

      // Log success for debugging
      console.log("Dashboard data loaded successfully:", {
        posts: postsData.length,
        stats: !!statsData,
        usage: !!usageData,
      });
    } catch (error) {
      console.error("Dashboard fetch error:", error);
      setError("Failed to load dashboard data");
    } finally {
      setLoading(false);
    }
  };

  const getQuickStats = () => {
    if (!stats || !usage) return [];

    return [
      {
        name: t("total_posts"),
        value: stats.overall?.total_posts || recentPosts.length || 0,
        icon: ClockIcon,
        color: "bg-blue-500",
      },
      {
        name: t("messages_sent"),
        value: stats.overall?.total_sent || 0,
        icon: ChartBarIcon,
        color: "bg-green-500",
      },
      {
        name: t("active_groups"),
        value: usage.usage?.groups?.used || 0,
        icon: UserGroupIcon,
        color: "bg-purple-500",
      },
      {
        name: t("this_month_revenue"),
        value: `${stats.overall?.currency || "USD"} ${(
          stats.overall?.total_revenue || 0
        ).toFixed(2)}`,
        icon: CalendarIcon,
        color: "bg-yellow-500",
      },
    ];
  };

  const formatPostDate = (dateString) => {
    try {
      return new Date(dateString).toLocaleString();
    } catch (error) {
      return "Invalid date";
    }
  };

  const getPostStatus = (post) => {
    // Determine post status based on schedule times
    const now = new Date();
    const scheduleTimes = post.schedule_times || [];

    if (scheduleTimes.length === 0) {
      return { label: "Draft", color: "bg-gray-100 text-gray-800" };
    }

    const futureTimes = scheduleTimes.filter((time) => new Date(time) > now);

    if (futureTimes.length > 0) {
      return { label: "Active", color: "bg-green-100 text-green-800" };
    } else {
      return { label: "Completed", color: "bg-blue-100 text-blue-800" };
    }
  };

  if (loading) {
    return (
      <div className="flex justify-center items-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-indigo-600"></div>
      </div>
    );
  }

  const quickStats = getQuickStats();

  return (
    <div className="space-y-6">
      <div className="md:flex md:items-center md:justify-between">
        <h1 className="text-2xl font-bold text-gray-900">{t("dashboard")}</h1>
        <Link
          to="/posts/create"
          className="mt-4 md:mt-0 inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700"
        >
          <PlusIcon className="-ml-1 mr-2 h-5 w-5" />
          {t("schedule_new_post")}
        </Link>
      </div>

      {error && (
        <div className="rounded-md bg-yellow-50 p-4">
          <div className="flex">
            <div className="flex-shrink-0">
              <ExclamationIcon className="h-5 w-5 text-yellow-400" />
            </div>
            <div className="ml-3">
              <h3 className="text-sm font-medium text-yellow-800">
                Limited Data Available
              </h3>
              <div className="mt-2 text-sm text-yellow-700">
                <p>
                  Some dashboard data couldn't be loaded, showing basic
                  information.
                </p>
              </div>
              <div className="mt-4">
                <button
                  onClick={fetchDashboardData}
                  className="bg-yellow-100 px-2 py-1 text-sm text-yellow-800 rounded hover:bg-yellow-200"
                >
                  Retry
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Quick Stats */}
      <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
        {quickStats.map((stat) => (
          <div
            key={stat.name}
            className="bg-white overflow-hidden shadow rounded-lg"
          >
            <div className="p-5">
              <div className="flex items-center">
                <div className={`flex-shrink-0 rounded-md p-3 ${stat.color}`}>
                  <stat.icon className="h-6 w-6 text-white" />
                </div>
                <div className="ml-5 w-0 flex-1">
                  <dl>
                    <dt className="text-sm font-medium text-gray-500 truncate">
                      {stat.name}
                    </dt>
                    <dd className="text-lg font-medium text-gray-900">
                      {stat.value}
                    </dd>
                  </dl>
                </div>
              </div>
            </div>
          </div>
        ))}
      </div>

      {/* Usage Overview */}
      {usage && (
        <div className="bg-white shadow rounded-lg p-6">
          <h2 className="text-lg font-medium text-gray-900 mb-4">
            {t("usage_overview")}
          </h2>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div className="flex items-center space-x-4">
              <div className="w-24 h-24">
                <CircularProgressbar
                  value={usage.usage?.groups?.percentage || 0}
                  text={`${usage.usage?.groups?.used || 0}/${
                    usage.usage?.groups?.limit || 1
                  }`}
                  styles={buildStyles({
                    pathColor: "#4F46E5",
                    textColor: "#1F2937",
                    trailColor: "#E5E7EB",
                    textSize: "16px",
                  })}
                />
              </div>
              <div>
                <p className="text-sm font-medium text-gray-900">
                  {t("groups")}
                </p>
                <p className="text-sm text-gray-500">
                  {t("using_x_of_y", {
                    used: usage.usage?.groups?.used || 0,
                    limit: usage.usage?.groups?.limit || 1,
                  })}
                </p>
              </div>
            </div>

            <div className="flex items-center space-x-4">
              <div className="w-24 h-24">
                <CircularProgressbar
                  value={usage.usage?.messages?.percentage || 0}
                  text={`${usage.usage?.messages?.used || 0}/${
                    usage.usage?.messages?.limit || 3
                  }`}
                  styles={buildStyles({
                    pathColor: "#10B981",
                    textColor: "#1F2937",
                    trailColor: "#E5E7EB",
                    textSize: "16px",
                  })}
                />
              </div>
              <div>
                <p className="text-sm font-medium text-gray-900">
                  {t("messages_this_month")}
                </p>
                <p className="text-sm text-gray-500">
                  {t("using_x_of_y", {
                    used: usage.usage?.messages?.used || 0,
                    limit: usage.usage?.messages?.limit || 3,
                  })}
                </p>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Recent Posts */}
      <div className="bg-white shadow rounded-lg">
        <div className="px-6 py-4 border-b border-gray-200">
          <h2 className="text-lg font-medium text-gray-900">
            {t("recent_posts")}
          </h2>
        </div>
        <ul className="divide-y divide-gray-200">
          {recentPosts.length === 0 ? (
            <li className="px-6 py-12 text-center text-gray-500">
              <ClockIcon className="mx-auto h-12 w-12 text-gray-400 mb-4" />
              <h3 className="text-sm font-medium text-gray-900">
                {t("no_posts_yet")}
              </h3>
              <p className="mt-1 text-sm text-gray-500">
                Get started by scheduling your first post
              </p>
              <div className="mt-6">
                <Link
                  to="/posts/create"
                  className="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700"
                >
                  <PlusIcon className="-ml-1 mr-2 h-5 w-5" />
                  {t("schedule_new_post")}
                </Link>
              </div>
            </li>
          ) : (
            recentPosts.map((post) => {
              const status = getPostStatus(post);
              return (
                <li key={post._id || post.id} className="px-6 py-4">
                  <div className="flex items-center justify-between">
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center justify-between mb-2">
                        <p className="text-sm font-medium text-gray-900 truncate">
                          {/* Handle multiple groups display */}
                          {post.groups_data && post.groups_data.length > 0
                            ? post.groups_data.length === 1
                              ? post.groups_data[0].title
                              : `${post.groups_data.length} groups`
                            : post.group?.title || "Unknown Group"}
                        </p>
                        <span
                          className={`inline-flex px-2 py-1 text-xs rounded-full ${status.color}`}
                        >
                          {status.label}
                        </span>
                      </div>

                      <p className="text-sm text-gray-500 truncate mb-2">
                        {post.content?.text || "No message text"}
                      </p>

                      <div className="flex items-center text-xs text-gray-400 space-x-4">
                        <span>
                          {t("created")}: {formatPostDate(post.created_at)}
                        </span>
                        {post.schedule_times &&
                          post.schedule_times.length > 0 && (
                            <span>
                              {post.schedule_times.length} scheduled time
                              {post.schedule_times.length !== 1 ? "s" : ""}
                            </span>
                          )}
                        {post.advertiser?.telegram_username && (
                          <span>@{post.advertiser.telegram_username}</span>
                        )}
                      </div>

                      {post.schedule_times &&
                        post.schedule_times.length > 0 && (
                          <p className="text-xs text-gray-400 mt-1">
                            {t("next_scheduled")}:{" "}
                            {formatPostDate(post.schedule_times[0])}
                          </p>
                        )}
                    </div>
                  </div>
                </li>
              );
            })
          )}
        </ul>
        {recentPosts.length > 0 && (
          <div className="px-6 py-3 bg-gray-50">
            <Link
              to="/posts"
              className="text-sm font-medium text-indigo-600 hover:text-indigo-500"
            >
              {t("view_all_posts")} →
            </Link>
          </div>
        )}
      </div>
    </div>
  );
};

export default Dashboard;
