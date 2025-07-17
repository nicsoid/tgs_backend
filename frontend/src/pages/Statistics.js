// src/pages/Statistics.js - Improved Group Statistics

import React, { useState, useEffect } from "react";
import { useTranslation } from "react-i18next";
import axios from "axios";
import {
  LineChart,
  Line,
  BarChart,
  Bar,
  PieChart,
  Pie,
  Cell,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  Legend,
  ResponsiveContainer,
} from "recharts";
import {
  CurrencyDollarIcon,
  ChartBarIcon,
  UserGroupIcon,
  ExclamationTriangleIcon,
} from "@heroicons/react/outline";

const Statistics = () => {
  const { t } = useTranslation();
  const [stats, setStats] = useState(null);
  const [loading, setLoading] = useState(true);
  const [selectedPost, setSelectedPost] = useState(null);
  const [postDetails, setPostDetails] = useState(null);
  const [error, setError] = useState(null);

  const COLORS = ["#0088FE", "#00C49F", "#FFBB28", "#FF8042", "#8884D8"];

  useEffect(() => {
    fetchStatistics();
  }, []);

  const fetchStatistics = async () => {
    try {
      setLoading(true);
      setError(null);
      const response = await axios.get(
        `${process.env.REACT_APP_API_URL}/api/statistics`
      );
      setStats(response.data);
    } catch (error) {
      console.error("Failed to fetch statistics:", error);
      setError("Failed to load statistics. Please try again.");
    } finally {
      setLoading(false);
    }
  };

  const fetchPostDetails = async (postId) => {
    try {
      const response = await axios.get(
        `${process.env.REACT_APP_API_URL}/api/statistics/post/${postId}`
      );
      setPostDetails(response.data);
      setSelectedPost(postId);
    } catch (error) {
      console.error("Failed to fetch post details:", error);
    }
  };

  if (loading) {
    return (
      <div className="flex justify-center items-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-indigo-600"></div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="rounded-md bg-red-50 p-4">
        <div className="flex">
          <div className="flex-shrink-0">
            <ExclamationTriangleIcon className="h-5 w-5 text-red-400" />
          </div>
          <div className="ml-3">
            <h3 className="text-sm font-medium text-red-800">Error</h3>
            <div className="mt-2 text-sm text-red-700">
              <p>{error}</p>
            </div>
            <div className="mt-4">
              <button
                onClick={fetchStatistics}
                className="bg-red-100 px-2 py-1 text-sm text-red-800 rounded hover:bg-red-200"
              >
                Try Again
              </button>
            </div>
          </div>
        </div>
      </div>
    );
  }

  if (!stats) {
    return (
      <div className="text-center py-12">
        <p className="text-gray-500">No statistics available</p>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-semibold text-gray-900">
        {t("statistics")}
      </h1>

      {/* Overall Stats Cards */}
      <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
        <div className="bg-white overflow-hidden shadow rounded-lg">
          <div className="p-5">
            <div className="flex items-center">
              <div className="flex-shrink-0">
                <ChartBarIcon className="h-6 w-6 text-gray-400" />
              </div>
              <div className="ml-5 w-0 flex-1">
                <dl>
                  <dt className="text-sm font-medium text-gray-500 truncate">
                    {t("total_posts")}
                  </dt>
                  <dd className="text-lg font-medium text-gray-900">
                    {stats.overall.total_posts}
                  </dd>
                </dl>
              </div>
            </div>
          </div>
        </div>

        <div className="bg-white overflow-hidden shadow rounded-lg">
          <div className="p-5">
            <div className="flex items-center">
              <div className="flex-shrink-0">
                <UserGroupIcon className="h-6 w-6 text-gray-400" />
              </div>
              <div className="ml-5 w-0 flex-1">
                <dl>
                  <dt className="text-sm font-medium text-gray-500 truncate">
                    {t("total_sent")}
                  </dt>
                  <dd className="text-lg font-medium text-gray-900">
                    {stats.overall.total_sent}
                  </dd>
                </dl>
              </div>
            </div>
          </div>
        </div>

        <div className="bg-white overflow-hidden shadow rounded-lg">
          <div className="p-5">
            <div className="flex items-center">
              <div className="flex-shrink-0">
                <CurrencyDollarIcon className="h-6 w-6 text-gray-400" />
              </div>
              <div className="ml-5 w-0 flex-1">
                <dl>
                  <dt className="text-sm font-medium text-gray-500 truncate">
                    {t("total_revenue")}
                  </dt>
                  <dd className="text-lg font-medium text-gray-900">
                    {stats.overall.currency}{" "}
                    {(stats.overall.total_revenue || 0).toFixed(2)}
                  </dd>
                </dl>
              </div>
            </div>
          </div>
        </div>

        <div className="bg-white overflow-hidden shadow rounded-lg">
          <div className="p-5">
            <div className="flex items-center">
              <div className="flex-shrink-0">
                <ChartBarIcon className="h-6 w-6 text-gray-400" />
              </div>
              <div className="ml-5 w-0 flex-1">
                <dl>
                  <dt className="text-sm font-medium text-gray-500 truncate">
                    {t("success_rate")}
                  </dt>
                  <dd className="text-lg font-medium text-gray-900">
                    {stats.overall.total_posts > 0
                      ? (
                          (stats.overall.total_sent /
                            stats.overall.total_posts) *
                          100
                        ).toFixed(1)
                      : 0}
                    %
                  </dd>
                </dl>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Monthly Chart */}
      {stats.monthly && stats.monthly.length > 0 && (
        <div className="bg-white shadow rounded-lg p-6">
          <h2 className="text-lg font-medium text-gray-900 mb-4">
            {t("monthly_statistics")}
          </h2>
          <ResponsiveContainer width="100%" height={300}>
            <LineChart data={stats.monthly}>
              <CartesianGrid strokeDasharray="3 3" />
              <XAxis dataKey="month" />
              <YAxis yAxisId="left" />
              <YAxis yAxisId="right" orientation="right" />
              <Tooltip />
              <Legend />
              <Line
                yAxisId="left"
                type="monotone"
                dataKey="count"
                stroke="#8884d8"
                name={t("posts_sent")}
              />
              <Line
                yAxisId="right"
                type="monotone"
                dataKey="revenue"
                stroke="#82ca9d"
                name={t("revenue")}
              />
            </LineChart>
          </ResponsiveContainer>
        </div>
      )}

      {/* Top Advertisers */}
      {stats.top_advertisers && stats.top_advertisers.length > 0 && (
        <div className="bg-white shadow rounded-lg p-6">
          <h2 className="text-lg font-medium text-gray-900 mb-4">
            {t("top_advertisers")}
          </h2>
          <ResponsiveContainer width="100%" height={300}>
            <BarChart data={stats.top_advertisers.slice(0, 10)}>
              <CartesianGrid strokeDasharray="3 3" />
              <XAxis dataKey="username" />
              <YAxis />
              <Tooltip />
              <Bar dataKey="total_paid" fill="#8884d8" name={t("total_paid")} />
            </BarChart>
          </ResponsiveContainer>
        </div>
      )}

      {/* Group Statistics - Improved */}
      <div className="bg-white shadow rounded-lg p-6">
        <h2 className="text-lg font-medium text-gray-900 mb-4">
          {t("group_statistics")}
        </h2>

        {stats.group_stats && stats.group_stats.length > 0 ? (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t("group")}
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t("members")}
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t("total_posts")}
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Messages Sent
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Revenue ({stats.overall.currency})
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t("last_post")}
                  </th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {stats.group_stats.map((groupStat, index) => (
                  <tr
                    key={groupStat.group._id || index}
                    className="hover:bg-gray-50"
                  >
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="flex items-center">
                        <div className="text-sm font-medium text-gray-900">
                          {groupStat.group.title}
                        </div>
                        {groupStat.group.username && (
                          <div className="text-xs text-gray-500 ml-2">
                            @{groupStat.group.username}
                          </div>
                        )}
                      </div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                      {groupStat.group.member_count?.toLocaleString() || 0}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-medium">
                      {groupStat.total_posts}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                      {groupStat.total_messages_sent || 0}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                      {groupStat.total_revenue
                        ? groupStat.total_revenue.toFixed(2)
                        : "0.00"}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                      {groupStat.last_post
                        ? new Date(groupStat.last_post).toLocaleDateString()
                        : t("never")}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <div className="text-center py-8">
            <UserGroupIcon className="mx-auto h-12 w-12 text-gray-400" />
            <h3 className="mt-2 text-sm font-medium text-gray-900">
              No Group Data
            </h3>
            <p className="mt-1 text-sm text-gray-500">
              Add some groups and schedule posts to see group statistics.
            </p>
          </div>
        )}
      </div>

      {/* Post Details Modal */}
      {postDetails && (
        <div className="fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center z-50">
          <div className="bg-white rounded-lg max-w-3xl w-full max-h-[90vh] overflow-y-auto p-6">
            <h3 className="text-lg font-medium text-gray-900 mb-4">
              {t("post_details")}
            </h3>

            <div className="space-y-4">
              <div>
                <p className="text-sm text-gray-500">{t("content")}</p>
                <p className="text-sm text-gray-900">
                  {postDetails.post.content.text}
                </p>
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <p className="text-sm text-gray-500">{t("advertiser")}</p>
                  <p className="text-sm text-gray-900">
                    @{postDetails.statistics.advertiser.telegram_username}
                  </p>
                </div>
                <div>
                  <p className="text-sm text-gray-500">{t("amount_paid")}</p>
                  <p className="text-sm text-gray-900">
                    {postDetails.statistics.advertiser.currency}{" "}
                    {postDetails.statistics.advertiser.amount_paid}
                  </p>
                </div>
              </div>

              <div>
                <p className="text-sm text-gray-500 mb-2">
                  {t("send_history")}
                </p>
                <div className="overflow-x-auto">
                  <table className="min-w-full divide-y divide-gray-200">
                    <thead className="bg-gray-50">
                      <tr>
                        <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">
                          {t("scheduled_time")}
                        </th>
                        <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">
                          {t("sent_at")}
                        </th>
                        <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">
                          {t("status")}
                        </th>
                      </tr>
                    </thead>
                    <tbody className="bg-white divide-y divide-gray-200">
                      {postDetails.logs.map((log) => (
                        <tr key={log._id}>
                          <td className="px-4 py-2 text-sm text-gray-900">
                            {new Date(log.scheduled_time).toLocaleString()}
                          </td>
                          <td className="px-4 py-2 text-sm text-gray-900">
                            {log.sent_at
                              ? new Date(log.sent_at).toLocaleString()
                              : "-"}
                          </td>
                          <td className="px-4 py-2 text-sm">
                            <span
                              className={`inline-flex px-2 py-1 text-xs rounded-full ${
                                log.status === "sent"
                                  ? "bg-green-100 text-green-800"
                                  : "bg-red-100 text-red-800"
                              }`}
                            >
                              {log.status}
                            </span>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

            <div className="mt-6 flex justify-end">
              <button
                onClick={() => {
                  setSelectedPost(null);
                  setPostDetails(null);
                }}
                className="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50"
              >
                {t("close")}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default Statistics;
