// src/pages/Dashboard.js

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
} from "@heroicons/react/outline";
import { CircularProgressbar, buildStyles } from "react-circular-progressbar";
import "react-circular-progressbar/dist/styles.css";

const Dashboard = () => {
  const { t } = useTranslation();
  const [stats, setStats] = useState(null);
  const [usage, setUsage] = useState(null);
  const [recentPosts, setRecentPosts] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    fetchDashboardData();
  }, []);

  const fetchDashboardData = async () => {
    try {
      const [statsRes, usageRes, postsRes] = await Promise.all([
        axios.get(`${process.env.REACT_APP_API_URL}/api/statistics`),
        axios.get(
          `${process.env.REACT_APP_API_URL}/api/scheduled-posts/usage/stats`
        ),
        axios.get(
          `${process.env.REACT_APP_API_URL}/api/scheduled-posts?limit=5`
        ),
      ]);

      setStats(statsRes.data);
      setUsage(usageRes.data);
      setRecentPosts(postsRes.data.data);
    } catch (error) {
      console.error("Failed to fetch dashboard data:", error);
    } finally {
      setLoading(false);
    }
  };

  if (loading) {
    return (
      <div className="flex justify-center items-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-indigo-600"></div>
      </div>
    );
  }

  const quickStats = [
    {
      name: t("total_posts"),
      value: stats?.overall.total_posts || 0,
      icon: ClockIcon,
      color: "bg-blue-500",
    },
    {
      name: t("messages_sent"),
      value: stats?.overall.total_sent || 0,
      icon: ChartBarIcon,
      color: "bg-green-500",
    },
    {
      name: t("active_groups"),
      value: usage?.usage.groups.used || 0,
      icon: UserGroupIcon,
      color: "bg-purple-500",
    },
    {
      name: t("this_month_revenue"),
      value: `${stats?.overall.currency || "USD"} ${
        stats?.overall.total_revenue?.toFixed(2) || "0.00"
      }`,
      icon: CalendarIcon,
      color: "bg-yellow-500",
    },
  ];

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
                  value={usage.usage.groups.percentage}
                  text={`${usage.usage.groups.used}/${usage.usage.groups.limit}`}
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
                    used: usage.usage.groups.used,
                    limit: usage.usage.groups.limit,
                  })}
                </p>
              </div>
            </div>

            <div className="flex items-center space-x-4">
              <div className="w-24 h-24">
                <CircularProgressbar
                  value={usage.usage.messages.percentage}
                  text={`${usage.usage.messages.used}/${usage.usage.messages.limit}`}
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
                    used: usage.usage.messages.used,
                    limit: usage.usage.messages.limit,
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
              {t("no_posts_yet")}
            </li>
          ) : (
            recentPosts.map((post) => (
              <li key={post._id} className="px-6 py-4">
                <div className="flex items-center justify-between">
                  <div className="flex-1 min-w-0">
                    <p className="text-sm font-medium text-gray-900 truncate">
                      {post.group?.title}
                    </p>
                    <p className="text-sm text-gray-500 truncate">
                      {post.content.text}
                    </p>
                    <p className="text-xs text-gray-400 mt-1">
                      {t("scheduled_for")}{" "}
                      {new Date(post.schedule_times[0]).toLocaleString()}
                    </p>
                  </div>
                  <div className="ml-4 flex-shrink-0">
                    <span
                      className={`inline-flex px-2 py-1 text-xs rounded-full ${
                        post.status === "pending"
                          ? "bg-yellow-100 text-yellow-800"
                          : post.status === "completed"
                          ? "bg-green-100 text-green-800"
                          : "bg-blue-100 text-blue-800"
                      }`}
                    >
                      {post.status}
                    </span>
                  </div>
                </div>
              </li>
            ))
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
