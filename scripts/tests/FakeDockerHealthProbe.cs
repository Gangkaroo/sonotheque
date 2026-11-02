using System;
using System.Threading;

public static class FakeDockerHealthProbe
{
    public static int Main()
    {
        var state = Environment.GetEnvironmentVariable("SONOTHEQUE_TEST_DOCKER_STATE");
        if (state == "timeout") Thread.Sleep(10000);
        if (state == "failure")
        {
            Console.Error.WriteLine("Docker engine unavailable");
            return 1;
        }
        if (state == "stopped") Console.WriteLine("{\"Running\":false}");
        else if (state == "starting") Console.WriteLine("{\"Running\":true,\"Health\":{\"Status\":\"starting\"}}");
        else Console.WriteLine("{\"Running\":true,\"Health\":{\"Status\":\"healthy\"}}");
        return 0;
    }
}
